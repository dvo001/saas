<?php
declare(strict_types=1);

use Sportlauf\Services\CategoryResolver;
use Sportlauf\Services\FinalistService;
use Sportlauf\Services\PdfService;
use Sportlauf\Services\RankingService;
use Sportlauf\Services\SheetNumberService;
use Sportlauf\Services\TimeParser;

require_once dirname(__DIR__) . '/app/Services/TimeParser.php';
require_once dirname(__DIR__) . '/app/Services/CategoryResolver.php';
require_once dirname(__DIR__) . '/app/Services/SheetNumberService.php';
require_once dirname(__DIR__) . '/app/Services/RankingService.php';
require_once dirname(__DIR__) . '/app/Services/FinalistService.php';
require_once dirname(__DIR__) . '/app/Services/PdfService.php';

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();
ob_start(static function (string $html): string {
    if (stripos($html, '<form') === false) {
        return $html;
    }

    return (string)preg_replace_callback(
        '/<form\b(?=[^>]*\bmethod=["\']?post["\']?)([^>]*)>/i',
        static fn (array $matches): string => '<form' . $matches[1] . '>' . csrfField(),
        $html
    );
});

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    $token = (string)($_POST['_csrf'] ?? '');
    if ($token === '' || !hash_equals(csrfToken(), $token)) {
        throw new RuntimeException('Ungueltige Formular-Sitzung. Bitte Seite neu laden.');
    }
}

function redirect(string $path, ?string $message = null): never
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
    }
    header('Location: ' . $path);
    exit;
}

function slugify(string $value): string
{
    $slug = strtolower(strtr($value, ['Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']));
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    return $slug !== '' ? $slug : 'organisation';
}

function config(): array
{
    $file = dirname(__DIR__) . '/config/database.php';
    if (!is_file($file)) {
        $file = dirname(__DIR__) . '/config/database.example.php';
    }

    return require $file;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['database'],
        $config['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function activeEvent(): ?array
{
    $tenant = activeTenant();
    if (!$tenant) {
        return null;
    }

    $selectedId = (int)($_SESSION['event_id'] ?? 0);
    if ($selectedId > 0) {
        $stmt = db()->prepare(
            'SELECT e.*, s.code AS sport_code, s.name AS sport_name
             FROM events e
             LEFT JOIN sports s ON s.id = e.sport_id
             WHERE e.id = :id AND e.tenant_id = :tenant_id'
        );
        $stmt->execute(['id' => $selectedId, 'tenant_id' => (int)$tenant['id']]);
        $event = $stmt->fetch();
        if ($event) {
            return $event;
        }
        unset($_SESSION['event_id']);
    }

    $stmt = db()->prepare(
        "SELECT e.*, s.code AS sport_code, s.name AS sport_name
         FROM events e
         LEFT JOIN sports s ON s.id = e.sport_id
         WHERE e.tenant_id = :tenant_id AND e.status = 'active'
         ORDER BY e.event_date DESC, e.id DESC LIMIT 1"
    );
    $stmt->execute(['tenant_id' => (int)$tenant['id']]);
    $event = $stmt->fetch();
    if (!$event) {
        $stmt = db()->prepare(
            'SELECT e.*, s.code AS sport_code, s.name AS sport_name
             FROM events e
             LEFT JOIN sports s ON s.id = e.sport_id
             WHERE e.tenant_id = :tenant_id
             ORDER BY e.event_date DESC, e.id DESC LIMIT 1'
        );
        $stmt->execute(['tenant_id' => (int)$tenant['id']]);
        $event = $stmt->fetch();
    }

    if (!$event) {
        return null;
    }

    $_SESSION['event_id'] = (int)$event['id'];
    return $event;
}

function requireEvent(): array
{
    requireTenant();
    $event = activeEvent();
    if (!$event) {
        redirect('/events', 'Bitte zuerst einen Anlass erstellen.');
    }

    return $event;
}

function currentUser(): ?array
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $stmt = db()->prepare("SELECT * FROM users WHERE id = :id AND status = 'active'");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['tenant_id'], $_SESSION['event_id']);
        return null;
    }

    return $user;
}

function requireUser(): array
{
    $user = currentUser();
    if (!$user) {
        redirect('/login');
    }

    return $user;
}

function userTenants(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT t.*, tu.role, p.name AS plan_name, p.max_events, p.max_users
         FROM tenants t
         JOIN tenant_users tu ON tu.tenant_id = t.id
         LEFT JOIN plans p ON p.id = t.plan_id
         WHERE tu.user_id = :user_id
         ORDER BY t.name'
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

function activeTenant(): ?array
{
    $user = currentUser();
    if (!$user) {
        return null;
    }

    $tenants = userTenants((int)$user['id']);
    if ($tenants === []) {
        return null;
    }

    $selectedId = (int)($_SESSION['tenant_id'] ?? 0);
    foreach ($tenants as $tenant) {
        if ((int)$tenant['id'] === $selectedId) {
            return $tenant;
        }
    }

    $_SESSION['tenant_id'] = (int)$tenants[0]['id'];
    return $tenants[0];
}

function requireTenant(): array
{
    requireUser();
    $tenant = activeTenant();
    if (!$tenant) {
        redirect('/onboarding', 'Bitte zuerst eine Organisation erstellen.');
    }

    return $tenant;
}

function roleLevel(string $role): int
{
    return match ($role) {
        'owner' => 40,
        'admin' => 30,
        'operator' => 20,
        'viewer' => 10,
        default => 0,
    };
}

function requireRole(string $minimumRole): array
{
    $tenant = requireTenant();
    if (roleLevel((string)$tenant['role']) < roleLevel($minimumRole)) {
        throw new RuntimeException('Keine Berechtigung fuer diese Aktion.');
    }

    return $tenant;
}

function tenantCanWrite(array $tenant): bool
{
    if (in_array((string)$tenant['status'], ['suspended', 'cancelled'], true)) {
        return false;
    }

    if ((string)$tenant['status'] === 'trial' && !empty($tenant['trial_ends_at'])) {
        return strtotime((string)$tenant['trial_ends_at']) >= time();
    }

    if (!empty($tenant['subscription_ends_at'])) {
        return strtotime((string)$tenant['subscription_ends_at']) >= time();
    }

    return in_array((string)$tenant['status'], ['trial', 'active', 'past_due'], true);
}

function requireWritableTenant(string $minimumRole = 'operator'): array
{
    $tenant = requireRole($minimumRole);
    if (!tenantCanWrite($tenant)) {
        throw new RuntimeException('Diese Organisation ist nicht aktiv. Bitte Subscription oder Status pruefen.');
    }

    return $tenant;
}

function enforcePlanLimit(array $tenant, string $limit): void
{
    if ($limit === 'events' && $tenant['max_events'] !== null) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM events WHERE tenant_id = :tenant_id');
        $stmt->execute(['tenant_id' => (int)$tenant['id']]);
        if ((int)$stmt->fetchColumn() >= (int)$tenant['max_events']) {
            throw new RuntimeException('Das Event-Limit des aktuellen Plans ist erreicht.');
        }
    }

    if ($limit === 'users' && $tenant['max_users'] !== null) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM tenant_users WHERE tenant_id = :tenant_id');
        $stmt->execute(['tenant_id' => (int)$tenant['id']]);
        if ((int)$stmt->fetchColumn() >= (int)$tenant['max_users']) {
            throw new RuntimeException('Das Benutzerlimit des aktuellen Plans ist erreicht.');
        }
    }
}

function render(string $title, callable $content): void
{
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $user = currentUser();
    $tenant = $user ? activeTenant() : null;
    $event = activeEvent();
    $links = [
        '/' => 'Dashboard',
        '/organization' => 'Organisation',
        '/billing' => 'Plan',
        '/events' => 'Anlaesse',
        '/sports' => 'Sportarten',
    ];
    if ($event && eventSupportsTimedResults($event)) {
        $links += [
            '/categories' => 'Jahrgangsgruppen',
            '/participants' => 'Teilnehmer',
            '/results' => 'Qualifikationszeiten',
            '/quick-entry' => 'Schnellerfassung',
            '/rankings/qualification' => 'Qualifikation',
        ];
        if ((int)$event['final_enabled'] === 1) {
            $links += ['/finalists' => 'Finalisten', '/final-results' => 'Finalzeiten'];
        }
        $links += [
            '/rankings' => 'Endrangliste',
            '/sheets/pdf' => 'Laufzettel',
            '/export/csv' => 'CSV Export',
        ];
    } elseif ($event) {
        $links += [
            '/sport-results' => 'Wertung',
        ];
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Laufanlaesse</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">Sportanlaesse</div>
        <?php if ($user): ?>
            <div class="tenant-meta">
                <strong><?= e($tenant['name'] ?? 'Keine Organisation') ?></strong>
                <span><?= e($tenant['plan_name'] ?? 'Kein Plan') ?> · <?= e($tenant['role'] ?? '') ?></span>
            </div>
            <?php if ($tenant): ?>
                <form class="event-switcher" method="post" action="/tenants/select">
                    <label>Organisation
                        <select name="tenant_id" onchange="this.form.submit()">
                            <?= tenantOptions((int)$tenant['id']) ?>
                        </select>
                    </label>
                    <noscript><button>Wechseln</button></noscript>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($event): ?>
            <form class="event-switcher" method="post" action="/events/select">
                <label>Anlass
                    <select name="event_id" onchange="this.form.submit()">
                        <?= eventOptions((int)$event['id']) ?>
                    </select>
                </label>
                <noscript><button>Wechseln</button></noscript>
            </form>
        <?php endif; ?>
        <nav class="nav">
            <?php foreach ($links as $href => $label): ?>
                <a class="<?= $path === $href ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($user): ?>
            <form class="logout-form" method="post" action="/logout"><button class="secondary">Abmelden</button></form>
        <?php endif; ?>
    </aside>
    <main class="main">
        <h1><?= e($title) ?></h1>
        <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
        <?php $content(); ?>
    </main>
</div>
</body>
</html><?php
}

function renderErrorPage(string $message): void
{
    ?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fehler - Laufanlaesse</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<main class="main">
    <h1>Fehler</h1>
    <div class="error"><?= e($message) ?></div>
</main>
</body>
</html><?php
}

function renderStandalone(string $title, callable $content): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Sportanlaesse</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<main class="main auth-main">
    <h1><?= e($title) ?></h1>
    <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
    <?php $content(); ?>
</main>
</body>
</html><?php
}

function eventOptions(?int $selected = null): string
{
    $tenant = activeTenant();
    if (!$tenant) {
        return '';
    }

    $html = '';
    $stmt = db()->prepare('SELECT id, name, event_date FROM events WHERE tenant_id = :tenant_id ORDER BY event_date DESC, id DESC');
    $stmt->execute(['tenant_id' => (int)$tenant['id']]);
    foreach ($stmt as $event) {
        $sel = (int)$event['id'] === $selected ? ' selected' : '';
        $html .= sprintf('<option value="%d"%s>%s (%s)</option>', $event['id'], $sel, e($event['name']), e($event['event_date']));
    }
    return $html;
}

function tenantOptions(?int $selected = null): string
{
    $user = requireUser();
    $html = '';
    foreach (userTenants((int)$user['id']) as $tenant) {
        $sel = (int)$tenant['id'] === $selected ? ' selected' : '';
        $html .= sprintf('<option value="%d"%s>%s</option>', $tenant['id'], $sel, e($tenant['name']));
    }
    return $html;
}

function sports(): array
{
    return db()->query('SELECT * FROM sports WHERE active = 1 ORDER BY name')->fetchAll();
}

function plans(): array
{
    return db()->query('SELECT * FROM plans WHERE active = 1 ORDER BY id')->fetchAll();
}

function sportOptions(?int $selected = null): string
{
    $html = '';
    foreach (sports() as $sport) {
        $sel = (int)$sport['id'] === $selected ? ' selected' : '';
        $html .= sprintf(
            '<option value="%d"%s data-mode="%s">%s</option>',
            $sport['id'],
            $sel,
            e($sport['scoring_mode']),
            e($sport['name'])
        );
    }
    return $html;
}

function sportById(int $sportId): ?array
{
    $stmt = db()->prepare('SELECT * FROM sports WHERE id = :id AND active = 1');
    $stmt->execute(['id' => $sportId]);
    $sport = $stmt->fetch();
    return $sport ?: null;
}

function defaultSport(): array
{
    $stmt = db()->query("SELECT * FROM sports WHERE code = 'running' LIMIT 1");
    $sport = $stmt->fetch();
    if ($sport) {
        return $sport;
    }

    $sport = db()->query('SELECT * FROM sports WHERE active = 1 ORDER BY id LIMIT 1')->fetch();
    if (!$sport) {
        throw new RuntimeException('Keine Sportart konfiguriert.');
    }

    return $sport;
}

function eventSupportsTimedResults(array $event): bool
{
    return ($event['sport_code'] ?? 'running') === 'running' || ($event['scoring_mode'] ?? 'timed') === 'timed';
}

function scoringModes(): array
{
    return [
        'timed' => 'Zeitwertung',
        'tournament' => 'Turnier / Gruppenphase',
        'points' => 'Punkte / Mehrkampf',
        'bracket' => 'K.-o.-Raster',
        'custom' => 'Freie Wertung',
    ];
}

function scoringModeOptions(string $selected = 'timed'): string
{
    $html = '';
    foreach (scoringModes() as $value => $label) {
        $sel = $value === $selected ? ' selected' : '';
        $html .= sprintf('<option value="%s"%s>%s</option>', e($value), $sel, e($label));
    }
    return $html;
}

function validScoringMode(string $mode, string $fallback = 'timed'): string
{
    return array_key_exists($mode, scoringModes()) ? $mode : $fallback;
}

function auditLog(string $action, ?int $tenantId = null, ?int $userId = null, ?string $entityType = null, ?string $entityId = null, array $metadata = []): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (tenant_id, user_id, action, entity_type, entity_id, metadata)
             VALUES (:tenant_id, :user_id, :action, :entity_type, :entity_id, :metadata)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    } catch (Throwable) {
        // Audit logging must never break event operations.
    }
}

function absoluteUrl(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . $path;
}

function validTenantRole(string $role): string
{
    return in_array($role, ['admin', 'operator', 'viewer'], true) ? $role : 'operator';
}

function invitationByToken(string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT i.*, t.name AS tenant_name
         FROM invitations i
         JOIN tenants t ON t.id = i.tenant_id
         WHERE i.token_hash = :token_hash
           AND i.accepted_at IS NULL
           AND i.expires_at >= NOW()'
    );
    $stmt->execute(['token_hash' => hash('sha256', $token)]);
    $invite = $stmt->fetch();
    return $invite ?: null;
}

function eventStatuses(): array
{
    return [
        'preparation' => 'Vorbereitung',
        'active' => 'Aktiv',
        'closed' => 'Abgeschlossen',
        'archived' => 'Archiviert',
    ];
}

function eventStatusOptions(string $selected = 'preparation'): string
{
    $html = '';
    foreach (eventStatuses() as $value => $label) {
        $sel = $value === $selected ? ' selected' : '';
        $html .= sprintf('<option value="%s"%s>%s</option>', e($value), $sel, e($label));
    }
    return $html;
}

function validEventStatus(string $status): string
{
    return array_key_exists($status, eventStatuses()) ? $status : 'preparation';
}

function eventConfiguration(array $data): array
{
    $qualificationRuns = max(1, min(2, (int)($data['qualification_runs'] ?? 2)));
    $finalEnabled = (int)($data['final_enabled'] ?? 0) === 1 ? 1 : 0;
    $finalistsPerGroup = max(1, min(99, (int)($data['finalists_per_group'] ?? 3)));

    return [
        'qualification_runs' => $qualificationRuns,
        'final_enabled' => $finalEnabled,
        'finalists_per_group' => $finalistsPerGroup,
    ];
}

function requireFinalEvent(): array
{
    $event = requireTimedEvent();
    if ((int)$event['final_enabled'] !== 1) {
        redirect('/', 'Fuer diesen Anlass ist kein Finallauf konfiguriert.');
    }

    return $event;
}

function requireTimedEvent(): array
{
    $event = requireEvent();
    if (!eventSupportsTimedResults($event)) {
        redirect('/', 'Diese Funktion ist nur fuer zeitbasierte Laufanlaesse verfuegbar.');
    }

    return $event;
}

function formatEventDate(?string $date): string
{
    $date = trim((string)$date);
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$parsed) {
        return $date;
    }

    $months = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mrz',
        4 => 'Apr',
        5 => 'Mai',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Aug',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Dez',
    ];

    return sprintf('%02d.%s.%04d', (int)$parsed->format('d'), $months[(int)$parsed->format('n')], (int)$parsed->format('Y'));
}

function eventFileName(array $event, string $document, string $extension): string
{
    $slug = strtolower(strtr((string)$event['name'], ['Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue']));
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    if ($slug === '') {
        $slug = 'anlass-' . (int)$event['id'];
    }

    return sprintf('%s-%s-%s.%s', $document, $event['event_date'], $slug, $extension);
}

function categoriesForEvent(int $eventId): array
{
    $stmt = db()->prepare('SELECT * FROM categories WHERE event_id = :event_id ORDER BY sort_order, year_from DESC, id');
    $stmt->execute(['event_id' => $eventId]);
    return $stmt->fetchAll();
}

function saveParticipant(array $data, ?int $participantId = null): int
{
    $pdo = db();
    $eventId = (int)$data['event_id'];
    $birthYear = (int)$data['birth_year'];
    $gender = $data['gender'];
    if (!in_array($gender, ['female', 'male'], true)) {
        throw new InvalidArgumentException('Geschlecht ist ungueltig.');
    }
    if ($birthYear < 1900 || $birthYear > 2100) {
        throw new InvalidArgumentException('Jahrgang muss vierstellig sein.');
    }

    $category = (new CategoryResolver($pdo))->resolve($eventId, $birthYear);
    $categoryId = $category['id'] ?? null;

    if ($participantId === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO participants
             (event_id, category_id, sheet_number, last_name, first_name, birth_year, gender, school_class, city, notes)
             VALUES (:event_id, :category_id, :sheet_number, :last_name, :first_name, :birth_year, :gender, :school_class, :city, :notes)'
        );
        $stmt->execute([
            'event_id' => $eventId,
            'category_id' => $categoryId,
            'sheet_number' => trim($data['sheet_number']),
            'last_name' => trim($data['last_name']),
            'first_name' => trim($data['first_name']),
            'birth_year' => $birthYear,
            'gender' => $gender,
            'school_class' => trim((string)($data['school_class'] ?? '')),
            'city' => trim((string)($data['city'] ?? '')),
            'notes' => trim((string)($data['notes'] ?? '')),
        ]);
        $participantId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO results (participant_id) VALUES (:participant_id)')
            ->execute(['participant_id' => $participantId]);
        return $participantId;
    }

    $stmt = $pdo->prepare(
        'UPDATE participants SET
         event_id = :event_id, category_id = :category_id, sheet_number = :sheet_number,
         last_name = :last_name, first_name = :first_name, birth_year = :birth_year,
         gender = :gender, school_class = :school_class, city = :city, notes = :notes
         WHERE id = :id'
    );
    $stmt->execute([
        'event_id' => $eventId,
        'category_id' => $categoryId,
        'sheet_number' => trim($data['sheet_number']),
        'last_name' => trim($data['last_name']),
        'first_name' => trim($data['first_name']),
        'birth_year' => $birthYear,
        'gender' => $gender,
        'school_class' => trim((string)($data['school_class'] ?? '')),
        'city' => trim((string)($data['city'] ?? '')),
        'notes' => trim((string)($data['notes'] ?? '')),
        'id' => $participantId,
    ]);

    return $participantId;
}

function saveResult(int $participantId, array $data, int $qualificationRuns = 2): void
{
    $run1 = TimeParser::parse($data['run1_time'] ?? null);
    $run2 = $qualificationRuns > 1 ? TimeParser::parse($data['run2_time'] ?? null) : null;
    $best = TimeParser::best($run1, $run2);
    $status = $best === null ? ($data['qualification_status'] ?? 'no_time') : 'valid';
    if (!in_array($status, ['no_time', 'valid', 'dns', 'dnf', 'dsq'], true)) {
        $status = 'no_time';
    }

    $stmt = db()->prepare(
        'INSERT INTO results (participant_id, run1_time_tenths, run2_time_tenths, best_qualification_time_tenths, qualification_status, notes)
         VALUES (:participant_id, :run1, :run2, :best, :status, :notes)
         ON DUPLICATE KEY UPDATE
           run1_time_tenths = VALUES(run1_time_tenths),
           run2_time_tenths = VALUES(run2_time_tenths),
           best_qualification_time_tenths = VALUES(best_qualification_time_tenths),
           qualification_status = VALUES(qualification_status),
           notes = VALUES(notes)'
    );
    $stmt->execute([
        'participant_id' => $participantId,
        'run1' => $run1,
        'run2' => $run2,
        'best' => $best,
        'status' => $status,
        'notes' => trim((string)($data['result_notes'] ?? '')),
    ]);
}

function renderRankingTable(array $rows, array $event, bool $final = false): void
{
    $hasSecondRun = (int)$event['qualification_runs'] > 1;
    $hasFinal = (int)$event['final_enabled'] === 1;
    ?><table>
        <thead><tr>
            <th>Rang</th><th>Name</th><th>Vorname</th><th>Jg.</th><th>Klasse</th><th>Ort</th>
            <th>Lauf 1</th><?php if ($hasSecondRun): ?><th>Lauf 2</th><?php endif; ?><th>Quali</th><?php if ($hasFinal): ?><th>Finale</th><?php endif; ?><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= (int)$row['rank'] ?></td>
                <td><?= e($row['last_name']) ?></td>
                <td><?= e($row['first_name']) ?></td>
                <td><?= e((string)$row['birth_year']) ?></td>
                <td><?= e($row['school_class']) ?></td>
                <td><?= e($row['city']) ?></td>
                <td><?= e(TimeParser::format($row['run1_time_tenths'] !== null ? (int)$row['run1_time_tenths'] : null)) ?></td>
                <?php if ($hasSecondRun): ?><td><?= e(TimeParser::format($row['run2_time_tenths'] !== null ? (int)$row['run2_time_tenths'] : null)) ?></td><?php endif; ?>
                <td><?= e(TimeParser::format((int)$row['best_qualification_time_tenths'])) ?></td>
                <?php if ($hasFinal): ?><td><?= e(TimeParser::format($row['final_time_tenths'] !== null ? (int)$row['final_time_tenths'] : null)) ?></td><?php endif; ?>
                <td><?= e($final ? ($row['ranking_segment'] ?? $row['final_status']) : $row['qualification_status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table><?php
}

function confirmedFinalistGroups(int $eventId): array
{
    $stmt = db()->prepare(
        'SELECT p.*, c.name AS category_name, c.sort_order, r.best_qualification_time_tenths
         FROM participants p
         JOIN categories c ON c.id = p.category_id
         JOIN results r ON r.participant_id = p.id
         WHERE p.event_id = :event_id
           AND r.finalist_confirmed = 1
         ORDER BY c.sort_order, c.year_from DESC, p.gender, r.best_qualification_time_tenths, p.last_name, p.first_name'
    );
    $stmt->execute(['event_id' => $eventId]);

    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $gender = $row['gender'] === 'female' ? 'Maedchen' : 'Knaben';
        $groups[$row['category_name'] . ' ' . $gender][] = $row;
    }

    return $groups;
}

function renderConfirmedFinalists(array $groups): void
{
    if ($groups === []) {
        echo '<div class="warning">Noch keine Finalisten bestaetigt.</div>';
        return;
    }

    foreach ($groups as $group => $rows) {
        echo '<h2>' . e($group) . '</h2>';
        ?><table>
            <thead><tr>
                <th>Start</th><th>Laufzettel</th><th>Name</th><th>Vorname</th><th>Jg.</th><th>Klasse</th><th>Ort</th><th>Qualizeit</th>
            </tr></thead>
            <tbody><?php
            $start = 1;
            foreach ($rows as $row) {
                ?><tr>
                    <td><?= $start++ ?></td>
                    <td><?= e($row['sheet_number']) ?></td>
                    <td><?= e($row['last_name']) ?></td>
                    <td><?= e($row['first_name']) ?></td>
                    <td><?= (int)$row['birth_year'] ?></td>
                    <td><?= e($row['school_class']) ?></td>
                    <td><?= e($row['city']) ?></td>
                    <td><?= e(TimeParser::format((int)$row['best_qualification_time_tenths'])) ?></td>
                </tr><?php
            }
            ?></tbody>
        </table><?php
    }
}

function printablePage(string $title, callable $content): string
{
    ob_start();
    ?><!doctype html><html lang="de"><head><meta charset="utf-8">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    </head><body class="printable"><main class="main"><h1><?= e($title) ?></h1><?php $content(); ?></main></body></html><?php
    return ob_get_clean();
}

function renderRunSheet(array $event, string $sheet): void
{
    $eventName = trim((string)$event['name']) !== '' ? (string)$event['name'] : 'Laufanlass';
    $eventLine = formatEventDate((string)$event['event_date']);
    $qualificationRuns = (int)$event['qualification_runs'];
    $finalistsPerGroup = (int)$event['finalists_per_group'];
    ?>
    <section class="run-sheet">
        <div class="run-sheet-header">
            <?php if (trim((string)$event['logo_path']) !== ''): ?>
                <div class="run-sheet-logo-cell">
                    <img class="run-sheet-logo" src="<?= e($event['logo_path']) ?>" alt="">
                </div>
            <?php endif; ?>
            <div class="run-sheet-title <?= trim((string)$event['logo_path']) === '' ? 'no-logo' : '' ?>">
                <h2>„<?= e($eventName) ?>“</h2>
                <p><?= e($eventLine) ?> · <?= e($event['distance_label']) ?></p>
            </div>
        </div>

        <div class="runner-number">Laeufer Nr. <?= e($sheet) ?></div>

        <div class="sheet-lines participant-lines">
            <div><span>Name:</span><i></i></div>
            <div><span>Vorname:</span><i></i></div>
            <div><span>Jahrgang:</span><i></i></div>
        </div>

        <div class="category-row">
            <strong>Kategorie:</strong>
            <div><b></b> Maedchen / Damen</div>
            <div><b></b> Knaben / Herren</div>
        </div>

        <div class="tear-line"><span>✂︎</span><i></i><span>✂︎</span></div>

        <h3>Zeitenteil · <?= e($sheet) ?></h3>

        <div class="sheet-lines time-lines">
            <div><span>Name:</span><i></i></div>
            <div><span>Vorname:</span><i></i></div>
            <div><span>Lauf 1:</span><i></i><em>Sek.</em></div>
            <?php if ($qualificationRuns > 1): ?><div><span>Lauf 2:</span><i></i><em>Sek.</em></div><?php endif; ?>
        </div>

        <p class="sheet-note">
            <?php if ($qualificationRuns > 1): ?>Es zaehlt die bessere der zwei Zeiten.<?php endif; ?>
            <?php if ((int)$event['final_enabled'] === 1): ?>Die <?= $finalistsPerGroup ?> Schnellsten pro Wertungsgruppe qualifizieren sich fuer das Finale.<?php endif; ?>
        </p>
    </section>
    <?php
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

try {
    if ($method === 'POST') {
        verifyCsrf();
    }

    if ($path === '/register' && $method === 'POST') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $organization = trim((string)($_POST['organization'] ?? ''));
        if ($name === '' || $email === '' || $password === '' || $organization === '') {
            throw new InvalidArgumentException('Name, E-Mail, Passwort und Organisation sind erforderlich.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('E-Mail-Adresse ist ungueltig.');
        }
        if (strlen($password) < 10) {
            throw new InvalidArgumentException('Das Passwort muss mindestens 10 Zeichen lang sein.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $plan = $pdo->query("SELECT id FROM plans WHERE code = 'starter' LIMIT 1")->fetch();
            $slugBase = slugify($organization);
            $slug = $slugBase;
            $suffix = 2;
            while (true) {
                $check = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE slug = :slug');
                $check->execute(['slug' => $slug]);
                if ((int)$check->fetchColumn() === 0) {
                    break;
                }
                $slug = $slugBase . '-' . $suffix++;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash)
                 VALUES (:name, :email, :password_hash)'
            );
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO tenants (plan_id, name, slug, contact_email, billing_email, status, trial_ends_at)
                 VALUES (:plan_id, :name, :slug, :contact_email, :billing_email, "trial", DATE_ADD(NOW(), INTERVAL 30 DAY))'
            );
            $stmt->execute([
                'plan_id' => $plan['id'] ?? null,
                'name' => $organization,
                'slug' => $slug,
                'contact_email' => $email,
                'billing_email' => $email,
            ]);
            $tenantId = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, "owner")')
                ->execute([$tenantId, $userId]);
            $pdo->prepare(
                'INSERT INTO subscriptions (tenant_id, plan_id, provider, status, current_period_ends_at)
                 VALUES (:tenant_id, :plan_id, "manual", "trialing", DATE_ADD(NOW(), INTERVAL 30 DAY))'
            )->execute(['tenant_id' => $tenantId, 'plan_id' => $plan['id'] ?? null]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $_SESSION['user_id'] = $userId;
        $_SESSION['tenant_id'] = $tenantId;
        auditLog('tenant.registered', $tenantId, $userId, 'tenant', (string)$tenantId, ['email' => $email]);
        redirect('/', 'Willkommen. Deine Organisation wurde erstellt.');
    }

    if ($path === '/register' && $method === 'GET') {
        renderStandalone('Registrieren', function (): void {
            ?><form method="post" class="panel grid">
                <label>Name<input required name="name" autocomplete="name"></label>
                <label>E-Mail<input required type="email" name="email" autocomplete="email"></label>
                <label>Organisation<input required name="organization"></label>
                <label>Passwort<input required type="password" name="password" minlength="10" autocomplete="new-password"></label>
                <div><button>Konto erstellen</button></div>
                <p><a href="/login">Schon registriert?</a></p>
            </form><?php
        });
        return;
    }

    if ($path === '/claim' && $method === 'GET') {
        $count = db()->query('SELECT COUNT(*) FROM tenant_users')->fetchColumn();
        if ((int)$count > 0) {
            redirect('/login', 'Diese Installation wurde bereits uebernommen.');
        }
        renderStandalone('Installation uebernehmen', function (): void {
            ?><form method="post" class="panel grid">
                <label>Name<input required name="name" autocomplete="name"></label>
                <label>E-Mail<input required type="email" name="email" autocomplete="email"></label>
                <label>Passwort<input required type="password" name="password" minlength="10" autocomplete="new-password"></label>
                <div><button>Als Owner uebernehmen</button></div>
            </form><?php
        });
        return;
    }

    if ($path === '/claim' && $method === 'POST') {
        $count = db()->query('SELECT COUNT(*) FROM tenant_users')->fetchColumn();
        if ((int)$count > 0) {
            redirect('/login', 'Diese Installation wurde bereits uebernommen.');
        }
        $tenant = db()->query('SELECT * FROM tenants ORDER BY id LIMIT 1')->fetch();
        if (!$tenant) {
            throw new RuntimeException('Keine Organisation zum Uebernehmen vorhanden.');
        }
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
            throw new InvalidArgumentException('Gueltige E-Mail und Passwort mit mindestens 10 Zeichen erforderlich.');
        }
        db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)')->execute([
            'name' => trim((string)$_POST['name']),
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $userId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, "owner")')->execute([(int)$tenant['id'], $userId]);
        $_SESSION['user_id'] = $userId;
        $_SESSION['tenant_id'] = (int)$tenant['id'];
        auditLog('tenant.claimed', (int)$tenant['id'], $userId, 'tenant', (string)$tenant['id']);
        redirect('/', 'Installation uebernommen.');
    }

    if ($path === '/login' && $method === 'POST') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $stmt = db()->prepare("SELECT * FROM users WHERE email = :email AND status = 'active'");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            throw new InvalidArgumentException('Login fehlgeschlagen.');
        }

        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => (int)$user['id']]);
        $_SESSION['user_id'] = (int)$user['id'];
        unset($_SESSION['tenant_id'], $_SESSION['event_id']);
        auditLog('user.login', null, (int)$user['id'], 'user', (string)$user['id']);
        redirect('/', 'Angemeldet.');
    }

    if ($path === '/login' && $method === 'GET') {
        renderStandalone('Anmelden', function (): void {
            ?><form method="post" class="panel grid">
                <label>E-Mail<input required type="email" name="email" autocomplete="email" autofocus></label>
                <label>Passwort<input required type="password" name="password" autocomplete="current-password"></label>
                <div><button>Anmelden</button></div>
                <p><a href="/register">Neue Organisation erstellen</a> · <a href="/password/forgot">Passwort vergessen</a></p>
            </form><?php
        });
        return;
    }

    if ($path === '/invite/accept' && $method === 'GET') {
        $token = (string)($_GET['token'] ?? '');
        $invite = invitationByToken($token);
        if (!$invite) {
            throw new InvalidArgumentException('Einladung nicht gefunden oder abgelaufen.');
        }

        renderStandalone('Einladung annehmen', function () use ($invite, $token): void {
            $user = currentUser();
            ?><div class="panel">
                <p><?= e($invite['tenant_name']) ?> · Rolle: <?= e($invite['role']) ?> · <?= e($invite['email']) ?></p>
                <form method="post" class="grid">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <?php if (!$user): ?>
                        <label>Name<input required name="name" autocomplete="name"></label>
                        <label>E-Mail<input required type="email" name="email" value="<?= e($invite['email']) ?>" readonly></label>
                        <label>Passwort<input required type="password" name="password" minlength="10" autocomplete="new-password"></label>
                    <?php endif; ?>
                    <div><button>Einladung annehmen</button></div>
                </form>
            </div><?php
        });
        return;
    }

    if ($path === '/invite/accept' && $method === 'POST') {
        $token = (string)($_POST['token'] ?? '');
        $invite = invitationByToken($token);
        if (!$invite) {
            throw new InvalidArgumentException('Einladung nicht gefunden oder abgelaufen.');
        }

        $user = currentUser();
        if (!$user) {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if ($email !== strtolower((string)$invite['email'])) {
                throw new InvalidArgumentException('Diese Einladung gehoert zu einer anderen E-Mail-Adresse.');
            }
            $existing = db()->prepare('SELECT * FROM users WHERE email = :email');
            $existing->execute(['email' => $email]);
            $found = $existing->fetch();
            if ($found) {
                redirect('/login', 'Bitte zuerst mit dieser E-Mail anmelden und den Einladungslink erneut oeffnen.');
            }
            $password = (string)($_POST['password'] ?? '');
            if (strlen($password) < 10) {
                throw new InvalidArgumentException('Das Passwort muss mindestens 10 Zeichen lang sein.');
            }
            $stmt = db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)');
            $stmt->execute([
                'name' => trim((string)$_POST['name']),
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $user = ['id' => (int)db()->lastInsertId(), 'email' => $email];
            $_SESSION['user_id'] = (int)$user['id'];
        }

        $tenant = [
            'id' => (int)$invite['tenant_id'],
            'max_users' => null,
            'status' => 'active',
        ];
        $planStmt = db()->prepare(
            'SELECT t.*, p.max_users
             FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id
             WHERE t.id = :id'
        );
        $planStmt->execute(['id' => (int)$invite['tenant_id']]);
        $tenant = $planStmt->fetch() ?: $tenant;
        enforcePlanLimit($tenant, 'users');

        db()->prepare(
            'INSERT INTO tenant_users (tenant_id, user_id, role)
             VALUES (:tenant_id, :user_id, :role)
             ON DUPLICATE KEY UPDATE role = VALUES(role)'
        )->execute([
            'tenant_id' => (int)$invite['tenant_id'],
            'user_id' => (int)$user['id'],
            'role' => (string)$invite['role'],
        ]);
        db()->prepare('UPDATE invitations SET accepted_at = NOW() WHERE id = :id')->execute(['id' => (int)$invite['id']]);
        $_SESSION['tenant_id'] = (int)$invite['tenant_id'];
        auditLog('invitation.accepted', (int)$invite['tenant_id'], (int)$user['id'], 'invitation', (string)$invite['id']);
        redirect('/', 'Einladung angenommen.');
    }

    if ($path === '/password/forgot' && $method === 'GET') {
        renderStandalone('Passwort zuruecksetzen', function (): void {
            ?><form method="post" class="panel grid">
                <label>E-Mail<input required type="email" name="email" autocomplete="email"></label>
                <div><button>Reset-Link erzeugen</button></div>
            </form><?php
        });
        return;
    }

    if ($path === '/password/forgot' && $method === 'POST') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $stmt = db()->prepare("SELECT * FROM users WHERE email = :email AND status = 'active'");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if ($user) {
            $token = bin2hex(random_bytes(32));
            db()->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
            )->execute(['user_id' => (int)$user['id'], 'token_hash' => hash('sha256', $token)]);
            redirect('/login', 'Reset-Link: ' . absoluteUrl('/password/reset?token=' . urlencode($token)));
        }
        redirect('/login', 'Falls die E-Mail existiert, wurde ein Reset-Link erzeugt.');
    }

    if ($path === '/password/reset' && $method === 'GET') {
        $token = (string)($_GET['token'] ?? '');
        renderStandalone('Neues Passwort', function () use ($token): void {
            ?><form method="post" class="panel grid">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label>Neues Passwort<input required type="password" name="password" minlength="10" autocomplete="new-password"></label>
                <div><button>Passwort speichern</button></div>
            </form><?php
        });
        return;
    }

    if ($path === '/password/reset' && $method === 'POST') {
        $token = (string)($_POST['token'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) < 10) {
            throw new InvalidArgumentException('Das Passwort muss mindestens 10 Zeichen lang sein.');
        }
        $stmt = db()->prepare(
            'SELECT * FROM password_resets
             WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at >= NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        $reset = $stmt->fetch();
        if (!$reset) {
            throw new InvalidArgumentException('Reset-Link nicht gefunden oder abgelaufen.');
        }
        db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute([
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => (int)$reset['user_id'],
        ]);
        db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')->execute(['id' => (int)$reset['id']]);
        auditLog('password.reset', null, (int)$reset['user_id'], 'user', (string)$reset['user_id']);
        redirect('/login', 'Passwort aktualisiert.');
    }

    if ($path === '/logout' && $method === 'POST') {
        session_destroy();
        redirect('/login');
    }

    $publicPaths = ['/login', '/register', '/claim', '/invite/accept', '/password/forgot', '/password/reset'];
    if (!in_array($path, $publicPaths, true)) {
        requireUser();
    }

    if ($path === '/onboarding' && $method === 'GET') {
        render('Organisation erstellen', function (): void {
            ?><div class="panel"><form method="post" action="/organization/create" class="grid">
                <label>Organisationsname<input required name="name"></label>
                <label>Kontakt-E-Mail<input required type="email" name="contact_email" value="<?= e(currentUser()['email'] ?? '') ?>"></label>
                <div><button>Organisation erstellen</button></div>
            </form></div><?php
        });
        return;
    }

    if ($path === '/organization/create' && $method === 'POST') {
        $user = requireUser();
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Organisationsname ist erforderlich.');
        }
        $plan = db()->query("SELECT id FROM plans WHERE code = 'starter' LIMIT 1")->fetch();
        $slugBase = slugify($name);
        $slug = $slugBase;
        $suffix = 2;
        while (true) {
            $check = db()->prepare('SELECT COUNT(*) FROM tenants WHERE slug = :slug');
            $check->execute(['slug' => $slug]);
            if ((int)$check->fetchColumn() === 0) {
                break;
            }
            $slug = $slugBase . '-' . $suffix++;
        }
        $stmt = db()->prepare(
            'INSERT INTO tenants (plan_id, name, slug, contact_email, billing_email, status, trial_ends_at)
             VALUES (:plan_id, :name, :slug, :contact_email, :billing_email, "trial", DATE_ADD(NOW(), INTERVAL 30 DAY))'
        );
        $stmt->execute([
            'plan_id' => $plan['id'] ?? null,
            'name' => $name,
            'slug' => $slug,
            'contact_email' => trim((string)($_POST['contact_email'] ?? $user['email'])),
            'billing_email' => trim((string)($_POST['contact_email'] ?? $user['email'])),
        ]);
        $tenantId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, "owner")')->execute([$tenantId, (int)$user['id']]);
        db()->prepare(
            'INSERT INTO subscriptions (tenant_id, plan_id, provider, status, current_period_ends_at)
             VALUES (:tenant_id, :plan_id, "manual", "trialing", DATE_ADD(NOW(), INTERVAL 30 DAY))'
        )->execute(['tenant_id' => $tenantId, 'plan_id' => $plan['id'] ?? null]);
        $_SESSION['tenant_id'] = $tenantId;
        unset($_SESSION['event_id']);
        auditLog('tenant.created', $tenantId, (int)$user['id'], 'tenant', (string)$tenantId);
        redirect('/', 'Organisation erstellt.');
    }

    if ($path === '/tenants/select' && $method === 'POST') {
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $user = requireUser();
        $stmt = db()->prepare('SELECT COUNT(*) FROM tenant_users WHERE tenant_id = :tenant_id AND user_id = :user_id');
        $stmt->execute(['tenant_id' => $tenantId, 'user_id' => (int)$user['id']]);
        if ((int)$stmt->fetchColumn() !== 1) {
            redirect('/', 'Organisation nicht gefunden.');
        }
        $_SESSION['tenant_id'] = $tenantId;
        unset($_SESSION['event_id']);
        redirect('/', 'Organisation gewechselt.');
    }

    if ($path === '/organization/invite' && $method === 'POST') {
        $tenant = requireWritableTenant('admin');
        enforcePlanLimit($tenant, 'users');
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('E-Mail-Adresse ist ungueltig.');
        }
        $role = validTenantRole((string)($_POST['role'] ?? 'operator'));
        $token = bin2hex(random_bytes(32));
        db()->prepare(
            'INSERT INTO invitations (tenant_id, email, role, token_hash, invited_by_user_id, expires_at)
             VALUES (:tenant_id, :email, :role, :token_hash, :invited_by_user_id, DATE_ADD(NOW(), INTERVAL 7 DAY))
             ON DUPLICATE KEY UPDATE
                role = VALUES(role),
                token_hash = VALUES(token_hash),
                invited_by_user_id = VALUES(invited_by_user_id),
                expires_at = VALUES(expires_at),
                accepted_at = NULL'
        )->execute([
            'tenant_id' => (int)$tenant['id'],
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by_user_id' => (int)(currentUser()['id'] ?? 0),
        ]);
        auditLog('invitation.created', (int)$tenant['id'], (int)(currentUser()['id'] ?? 0), 'invitation', $email, ['role' => $role]);
        redirect('/organization', 'Einladungslink: ' . absoluteUrl('/invite/accept?token=' . urlencode($token)));
    }

    if ($path === '/organization/member/update' && $method === 'POST') {
        $tenant = requireWritableTenant('admin');
        $userId = (int)($_POST['user_id'] ?? 0);
        $role = validTenantRole((string)($_POST['role'] ?? 'operator'));
        db()->prepare(
            'UPDATE tenant_users SET role = :role
             WHERE tenant_id = :tenant_id AND user_id = :user_id AND role <> "owner"'
        )->execute(['role' => $role, 'tenant_id' => (int)$tenant['id'], 'user_id' => $userId]);
        auditLog('member.role_updated', (int)$tenant['id'], (int)(currentUser()['id'] ?? 0), 'user', (string)$userId, ['role' => $role]);
        redirect('/organization', 'Rolle aktualisiert.');
    }

    if ($path === '/organization/member/remove' && $method === 'POST') {
        $tenant = requireWritableTenant('admin');
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === (int)(currentUser()['id'] ?? 0)) {
            throw new RuntimeException('Du kannst dich nicht selbst aus der Organisation entfernen.');
        }
        db()->prepare(
            'DELETE FROM tenant_users
             WHERE tenant_id = :tenant_id AND user_id = :user_id AND role <> "owner"'
        )->execute(['tenant_id' => (int)$tenant['id'], 'user_id' => $userId]);
        auditLog('member.removed', (int)$tenant['id'], (int)(currentUser()['id'] ?? 0), 'user', (string)$userId);
        redirect('/organization', 'Mitglied entfernt.');
    }

    if ($path === '/organization' && $method === 'GET') {
        render('Organisation', function (): void {
            $tenant = requireTenant();
            $canManage = roleLevel((string)$tenant['role']) >= roleLevel('admin') && tenantCanWrite($tenant);
            ?><div class="panel">
                <h2><?= e($tenant['name']) ?></h2>
                <p><?= e($tenant['status']) ?> · <?= e($tenant['plan_name'] ?? 'Starter') ?> · Rolle: <?= e($tenant['role']) ?></p>
            </div>
            <?php if ($canManage): ?>
                <div class="panel"><form method="post" action="/organization/invite" class="grid">
                    <label>E-Mail einladen<input required type="email" name="email"></label>
                    <label>Rolle<select name="role">
                        <option value="operator">Operator</option>
                        <option value="admin">Admin</option>
                        <option value="viewer">Viewer</option>
                    </select></label>
                    <div><button>Einladung erzeugen</button></div>
                </form></div>
            <?php endif; ?>
            <h2>Team</h2><table><thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th></tr></thead><tbody><?php
            $stmt = db()->prepare(
                'SELECT u.id, u.name, u.email, tu.role
                 FROM tenant_users tu JOIN users u ON u.id = tu.user_id
                 WHERE tu.tenant_id = :tenant_id ORDER BY tu.role, u.name'
            );
            $stmt->execute(['tenant_id' => (int)$tenant['id']]);
            foreach ($stmt as $row) {
                echo '<tr><td>' . e($row['name']) . '</td><td>' . e($row['email']) . '</td><td>';
                if ($canManage && $row['role'] !== 'owner') {
                    echo '<form method="post" action="/organization/member/update" class="inline-form"><input type="hidden" name="user_id" value="' . (int)$row['id'] . '"><select name="role">';
                    foreach (['admin' => 'Admin', 'operator' => 'Operator', 'viewer' => 'Viewer'] as $role => $label) {
                        echo '<option value="' . e($role) . '"' . ($row['role'] === $role ? ' selected' : '') . '>' . e($label) . '</option>';
                    }
                    echo '</select><button>Speichern</button></form>';
                    echo '<form method="post" action="/organization/member/remove" class="inline-form"><input type="hidden" name="user_id" value="' . (int)$row['id'] . '"><button class="danger">Entfernen</button></form>';
                } else {
                    echo e($row['role']);
                }
                echo '</td></tr>';
            }
            ?></tbody></table><?php
        });
        return;
    }

    if ($path === '/billing/update' && $method === 'POST') {
        $tenant = requireRole('owner');
        $planId = (int)($_POST['plan_id'] ?? 0);
        $status = (string)($_POST['status'] ?? 'active');
        if (!in_array($status, ['trial', 'active', 'past_due', 'suspended', 'cancelled'], true)) {
            $status = 'active';
        }
        $stmt = db()->prepare('SELECT * FROM plans WHERE id = :id AND active = 1');
        $stmt->execute(['id' => $planId]);
        $plan = $stmt->fetch();
        if (!$plan) {
            throw new InvalidArgumentException('Plan nicht gefunden.');
        }
        if ($plan['max_events'] !== null) {
            $count = db()->prepare('SELECT COUNT(*) FROM events WHERE tenant_id = :tenant_id');
            $count->execute(['tenant_id' => (int)$tenant['id']]);
            if ((int)$count->fetchColumn() > (int)$plan['max_events']) {
                throw new RuntimeException('Dieser Plan erlaubt weniger Anlaesse als aktuell vorhanden sind.');
            }
        }
        if ($plan['max_users'] !== null) {
            $count = db()->prepare('SELECT COUNT(*) FROM tenant_users WHERE tenant_id = :tenant_id');
            $count->execute(['tenant_id' => (int)$tenant['id']]);
            if ((int)$count->fetchColumn() > (int)$plan['max_users']) {
                throw new RuntimeException('Dieser Plan erlaubt weniger Benutzer als aktuell vorhanden sind.');
            }
        }
        db()->prepare('UPDATE tenants SET plan_id = :plan_id, status = :status WHERE id = :id')->execute([
            'plan_id' => $planId,
            'status' => $status,
            'id' => (int)$tenant['id'],
        ]);
        db()->prepare(
            'INSERT INTO subscriptions (tenant_id, plan_id, provider, status, current_period_ends_at)
             VALUES (:tenant_id, :plan_id, "manual", :subscription_status, DATE_ADD(NOW(), INTERVAL 1 MONTH))
             ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), status = VALUES(status), current_period_ends_at = VALUES(current_period_ends_at)'
        )->execute([
            'tenant_id' => (int)$tenant['id'],
            'plan_id' => $planId,
            'subscription_status' => $status === 'trial' ? 'trialing' : ($status === 'active' ? 'active' : ($status === 'past_due' ? 'past_due' : 'cancelled')),
        ]);
        auditLog('billing.updated', (int)$tenant['id'], (int)(currentUser()['id'] ?? 0), 'tenant', (string)$tenant['id'], ['plan_id' => $planId, 'status' => $status]);
        unset($_SESSION['tenant_id']);
        $_SESSION['tenant_id'] = (int)$tenant['id'];
        redirect('/billing', 'Plan aktualisiert.');
    }

    if ($path === '/billing' && $method === 'GET') {
        render('Plan', function (): void {
            $tenant = requireTenant();
            $canManage = (string)$tenant['role'] === 'owner' && tenantCanWrite($tenant);
            $events = db()->prepare('SELECT COUNT(*) FROM events WHERE tenant_id = :tenant_id');
            $events->execute(['tenant_id' => (int)$tenant['id']]);
            $users = db()->prepare('SELECT COUNT(*) FROM tenant_users WHERE tenant_id = :tenant_id');
            $users->execute(['tenant_id' => (int)$tenant['id']]);
            ?><div class="grid">
                <div class="metric"><strong><?= (int)$events->fetchColumn() ?></strong><span>Anlaesse</span></div>
                <div class="metric"><strong><?= (int)$users->fetchColumn() ?></strong><span>Benutzer</span></div>
                <div class="metric"><strong><?= e($tenant['plan_name'] ?? 'Kein Plan') ?></strong><span>Aktueller Plan</span></div>
                <div class="metric"><strong><?= e($tenant['status']) ?></strong><span>Status</span></div>
            </div>
            <?php if ($canManage): ?>
                <div class="panel"><form method="post" action="/billing/update" class="grid">
                    <label>Plan<select name="plan_id">
                        <?php foreach (plans() as $plan): ?>
                            <option value="<?= (int)$plan['id'] ?>" <?= (int)$tenant['plan_id'] === (int)$plan['id'] ? 'selected' : '' ?>><?= e($plan['name']) ?> · Events: <?= e($plan['max_events'] === null ? 'unlimitiert' : (string)$plan['max_events']) ?> · Benutzer: <?= e($plan['max_users'] === null ? 'unlimitiert' : (string)$plan['max_users']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>Status<select name="status">
                        <?php foreach (['trial', 'active', 'past_due', 'suspended', 'cancelled'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $tenant['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <div><button>Plan speichern</button></div>
                </form></div>
            <?php endif; ?><?php
        });
        return;
    }

    if ($path === '/sports' && $method === 'GET') {
        render('Sportarten', function (): void {
            ?><div class="panel"><p>Die Plattform ist fuer mehrere Sportarten vorbereitet. Laufanlaesse nutzen bereits die bestehende Zeitwertung; weitere Sportarten koennen als Turnier-, Punkte-, Raster- oder freie Wertung angelegt werden.</p></div>
            <table><thead><tr><th>Sportart</th><th>Code</th><th>Wertung</th></tr></thead><tbody><?php
            foreach (sports() as $sport) {
                echo '<tr><td>' . e($sport['name']) . '</td><td>' . e($sport['code']) . '</td><td>' . e(scoringModes()[$sport['scoring_mode']] ?? $sport['scoring_mode']) . '</td></tr>';
            }
            ?></tbody></table><?php
        });
        return;
    }

    if ($path === '/' && $method === 'GET') {
        render('Dashboard', function (): void {
            $event = activeEvent();
            if (!$event) {
                echo '<div class="warning">Noch kein Anlass vorhanden.</div><a class="button" href="/events">Anlass erstellen</a>';
                return;
            }
            $pdo = db();
            $eventId = (int)$event['id'];
            ?><div class="panel">
                <h2><?= e($event['name']) ?></h2>
                <p><?= e(formatEventDate($event['event_date'])) ?> · <?= e($event['sport_name'] ?? 'Sportart') ?> · <?= e($event['discipline_label'] ?: $event['distance_label']) ?> · <?= e(scoringModes()[$event['scoring_mode']] ?? $event['scoring_mode']) ?> · Status: <?= e(eventStatuses()[$event['status']] ?? $event['status']) ?></p>
            </div><?php
            if (!eventSupportsTimedResults($event)) {
                echo '<div class="warning">Fuer diese Sportart ist der Plattform-Rahmen vorbereitet. Die konkrete Wertungserfassung wird als eigenes Modul pro Sportart umgesetzt.</div>';
                echo '<div class="toolbar"><a class="button" href="/sport-results">Wertung erfassen</a><a class="button light" href="/events">Anlass bearbeiten</a><a class="button light" href="/sports">Sportarten anzeigen</a></div>';
                return;
            }
            $metrics = [
                'Personen' => 'SELECT COUNT(*) FROM participants WHERE event_id = ?',
                'Mit gueltiger Zeit' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.qualification_status = "valid"',
                'Ohne Zeit' => 'SELECT COUNT(*) FROM participants p LEFT JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND (r.best_qualification_time_tenths IS NULL OR r.id IS NULL)',
                'Ohne Kategorie' => 'SELECT COUNT(*) FROM participants WHERE event_id = ? AND category_id IS NULL',
            ];
            if ((int)$event['final_enabled'] === 1) {
                $metrics += [
                    'Vorgeschlagene Finalisten' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.is_finalist = 1',
                    'Bestaetigte Finalisten' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.finalist_confirmed = 1',
                    'Finalisten ohne Finalzeit' => 'SELECT COUNT(*) FROM participants p JOIN results r ON r.participant_id = p.id WHERE p.event_id = ? AND r.finalist_confirmed = 1 AND r.final_time_tenths IS NULL AND r.final_status = "qualified"',
                ];
            }
            echo '<div class="grid">';
            foreach ($metrics as $label => $sql) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$eventId]);
                echo '<div class="metric"><strong>' . (int)$stmt->fetchColumn() . '</strong><span>' . e($label) . '</span></div>';
            }
            echo '</div>';
            ?><div class="toolbar">
                <a class="button" href="/rankings/qualification">Qualifikationsrangliste</a>
                <?php if ((int)$event['final_enabled'] === 1): ?>
                    <a class="button" href="/finalists">Finalisten</a>
                    <a class="button" href="/final-results">Finalzeiten</a>
                <?php endif; ?>
                <a class="button" href="/rankings">Endrangliste</a>
            </div><?php
        });
        return;
    }

    if ($path === '/events' && $method === 'POST') {
        $tenant = requireWritableTenant('operator');
        enforcePlanLimit($tenant, 'events');
        $sport = sportById((int)($_POST['sport_id'] ?? 0)) ?? defaultSport();
        $configuration = eventConfiguration($_POST);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO events
                 (tenant_id, sport_id, name, event_date, distance_label, discipline_label, scoring_mode, time_window, qualification_runs, final_enabled, finalists_per_group, logo_path, status, notes)
                 VALUES (:tenant_id, :sport_id, :name, :event_date, :distance_label, :discipline_label, :scoring_mode, :time_window, :qualification_runs, :final_enabled, :finalists_per_group, :logo_path, :status, :notes)'
            );
            $stmt->execute([
                'tenant_id' => (int)$tenant['id'],
                'sport_id' => (int)$sport['id'],
                'name' => trim($_POST['name']),
                'event_date' => $_POST['event_date'],
                'distance_label' => trim($_POST['distance_label']),
                'discipline_label' => trim((string)($_POST['discipline_label'] ?? '')),
                'scoring_mode' => validScoringMode((string)($_POST['scoring_mode'] ?? $sport['scoring_mode']), (string)$sport['scoring_mode']),
                'time_window' => trim((string)($_POST['time_window'] ?? '')),
                ...$configuration,
                'logo_path' => trim((string)($_POST['logo_path'] ?? '')),
                'status' => validEventStatus((string)$_POST['status']),
                'notes' => trim((string)($_POST['notes'] ?? '')),
            ]);
            $eventId = (int)$pdo->lastInsertId();
            $templateId = (int)($_POST['copy_categories_from'] ?? 0);
            if ($templateId > 0 && $templateId !== $eventId) {
                $copy = $pdo->prepare(
                    'INSERT INTO categories (event_id, name, year_from, year_to, sort_order, active)
                     SELECT :event_id, name, year_from, year_to, sort_order, active
                     FROM categories
                     WHERE event_id = :template_id
                       AND EXISTS (SELECT 1 FROM events e WHERE e.id = categories.event_id AND e.tenant_id = :tenant_id)'
                );
                $copy->execute(['event_id' => $eventId, 'template_id' => $templateId, 'tenant_id' => (int)$tenant['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $_SESSION['event_id'] = $eventId;
        auditLog('event.created', (int)$tenant['id'], (int)(currentUser()['id'] ?? 0), 'event', (string)$eventId);
        redirect('/events', 'Anlass erstellt und ausgewaehlt.');
    }

    if ($path === '/events/update' && $method === 'POST') {
        $tenant = requireWritableTenant('operator');
        $sport = sportById((int)($_POST['sport_id'] ?? 0)) ?? defaultSport();
        $configuration = eventConfiguration($_POST);
        $stmt = db()->prepare(
            'UPDATE events SET sport_id = :sport_id, name = :name, event_date = :event_date, distance_label = :distance_label,
             discipline_label = :discipline_label, scoring_mode = :scoring_mode,
             time_window = :time_window, qualification_runs = :qualification_runs,
             final_enabled = :final_enabled, finalists_per_group = :finalists_per_group,
             logo_path = :logo_path, status = :status, notes = :notes
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            'sport_id' => (int)$sport['id'],
            'name' => trim($_POST['name']),
            'event_date' => $_POST['event_date'],
            'distance_label' => trim($_POST['distance_label']),
            'discipline_label' => trim((string)($_POST['discipline_label'] ?? '')),
            'scoring_mode' => validScoringMode((string)($_POST['scoring_mode'] ?? $sport['scoring_mode']), (string)$sport['scoring_mode']),
            'time_window' => trim((string)($_POST['time_window'] ?? '')),
            ...$configuration,
            'logo_path' => trim((string)($_POST['logo_path'] ?? '')),
            'status' => validEventStatus((string)$_POST['status']),
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'id' => (int)$_POST['id'],
            'tenant_id' => (int)$tenant['id'],
        ]);
        if ($configuration['qualification_runs'] === 1) {
            $resetSecondRuns = db()->prepare(
                'UPDATE results r JOIN participants p ON p.id = r.participant_id
                 SET r.run2_time_tenths = NULL,
                     r.best_qualification_time_tenths = r.run1_time_tenths,
                     r.qualification_status = CASE
                         WHEN r.run1_time_tenths IS NOT NULL THEN "valid"
                         WHEN r.qualification_status = "valid" THEN "no_time"
                         ELSE r.qualification_status
                     END
                 WHERE p.event_id = :event_id
                   AND p.event_id IN (SELECT id FROM events WHERE tenant_id = :tenant_id)'
            );
            $resetSecondRuns->execute(['event_id' => (int)$_POST['id'], 'tenant_id' => (int)$tenant['id']]);
        }
        auditLog('event.updated', (int)$tenant['id'], (int)(currentUser()['id'] ?? 0), 'event', (string)((int)$_POST['id']));
        redirect('/events', 'Anlass aktualisiert.');
    }

    if ($path === '/events/select' && $method === 'POST') {
        $tenant = requireTenant();
        $eventId = (int)($_POST['event_id'] ?? 0);
        $stmt = db()->prepare('SELECT COUNT(*) FROM events WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute(['id' => $eventId, 'tenant_id' => (int)$tenant['id']]);
        if ((int)$stmt->fetchColumn() !== 1) {
            redirect('/events', 'Anlass nicht gefunden.');
        }
        $_SESSION['event_id'] = $eventId;
        redirect('/', 'Anlass gewechselt.');
    }

    if ($path === '/events/delete' && $method === 'POST') {
        $tenant = requireWritableTenant('admin');
        $stmt = db()->prepare('DELETE FROM events WHERE id = :id AND tenant_id = :tenant_id');
        $eventId = (int)$_POST['id'];
        $stmt->execute(['id' => $eventId, 'tenant_id' => (int)$tenant['id']]);
        if ((int)($_SESSION['event_id'] ?? 0) === $eventId) {
            unset($_SESSION['event_id']);
        }

        $message = $stmt->rowCount() > 0 ? 'Anlass geloescht.' : 'Anlass nicht gefunden.';
        if ($stmt->rowCount() > 0) {
            auditLog('event.deleted', (int)$tenant['id'], (int)(currentUser()['id'] ?? 0), 'event', (string)$eventId);
        }
        redirect('/events', $message);
    }

    if ($path === '/events' && $method === 'GET') {
        render('Anlaesse', function (): void {
            $tenant = requireTenant();
            $defaultSport = defaultSport();
            ?><div class="panel"><form method="post" class="grid">
                <label>Name<input required name="name"></label>
                <label>Datum<input required type="date" name="event_date" value="<?= date('Y-m-d') ?>"></label>
                <label>Sportart<select name="sport_id"><?= sportOptions((int)$defaultSport['id']) ?></select></label>
                <label>Disziplin / Format<input name="discipline_label" placeholder="z. B. Sprint, U11-Turnier, Kata"></label>
                <label>Strecke / Kurzlabel<input required name="distance_label" placeholder="z. B. 2x300m, Halle 1, U13"></label>
                <label>Wertungsmodus<select name="scoring_mode"><?= scoringModeOptions((string)$defaultSport['scoring_mode']) ?></select></label>
                <label>Zeitfenster<input name="time_window"></label>
                <label>Qualifikationslaeufe<select name="qualification_runs"><option value="1">1 Lauf</option><option value="2" selected>2 Laeufe (beste Zeit)</option></select></label>
                <label>Finallauf<select name="final_enabled"><option value="1" selected>Ja</option><option value="0">Nein</option></select></label>
                <label>Finalplaetze pro Gruppe<input required type="number" min="1" max="99" name="finalists_per_group" value="3"></label>
                <label>Logo-Pfad (optional)<input name="logo_path" placeholder="/assets/img/mein-logo.png"></label>
                <label>Status<select name="status"><?= eventStatusOptions('active') ?></select></label>
                <label>Jahrgangsgruppen uebernehmen<select name="copy_categories_from"><option value="0">Keine</option><?= eventOptions() ?></select></label>
                <label>Bemerkung<textarea name="notes"></textarea></label>
                <div><button>Anlass erstellen</button></div>
            </form></div>
            <h2>Vorhandene Anlaesse</h2><?php
            $selectedId = (int)(activeEvent()['id'] ?? 0);
            $stmt = db()->prepare(
                'SELECT e.*, s.name AS sport_name
                 FROM events e
                 LEFT JOIN sports s ON s.id = e.sport_id
                 WHERE e.tenant_id = :tenant_id
                 ORDER BY e.event_date DESC, e.id DESC'
            );
            $stmt->execute(['tenant_id' => (int)$tenant['id']]);
            foreach ($stmt as $event) {
                ?><div class="panel event-card">
                    <h3><?= e($event['name']) ?><?= (int)$event['id'] === $selectedId ? ' · ausgewaehlt' : '' ?></h3>
                    <form method="post" action="/events/update" class="grid">
                        <input type="hidden" name="id" value="<?= (int)$event['id'] ?>">
                        <label>Name<input required name="name" value="<?= e($event['name']) ?>"></label>
                        <label>Datum<input required type="date" name="event_date" value="<?= e($event['event_date']) ?>"></label>
                        <label>Sportart<select name="sport_id"><?= sportOptions((int)$event['sport_id']) ?></select></label>
                        <label>Disziplin / Format<input name="discipline_label" value="<?= e($event['discipline_label']) ?>"></label>
                        <label>Strecke / Kurzlabel<input required name="distance_label" value="<?= e($event['distance_label']) ?>"></label>
                        <label>Wertungsmodus<select name="scoring_mode"><?= scoringModeOptions((string)$event['scoring_mode']) ?></select></label>
                        <label>Zeitfenster<input name="time_window" value="<?= e($event['time_window']) ?>"></label>
                        <label>Qualifikationslaeufe<select name="qualification_runs"><option value="1" <?= (int)$event['qualification_runs'] === 1 ? 'selected' : '' ?>>1 Lauf</option><option value="2" <?= (int)$event['qualification_runs'] === 2 ? 'selected' : '' ?>>2 Laeufe (beste Zeit)</option></select></label>
                        <label>Finallauf<select name="final_enabled"><option value="1" <?= (int)$event['final_enabled'] === 1 ? 'selected' : '' ?>>Ja</option><option value="0" <?= (int)$event['final_enabled'] === 0 ? 'selected' : '' ?>>Nein</option></select></label>
                        <label>Finalplaetze pro Gruppe<input required type="number" min="1" max="99" name="finalists_per_group" value="<?= (int)$event['finalists_per_group'] ?>"></label>
                        <label>Logo-Pfad (optional)<input name="logo_path" value="<?= e($event['logo_path']) ?>" placeholder="/assets/img/mein-logo.png"></label>
                        <label>Status<select name="status"><?= eventStatusOptions((string)$event['status']) ?></select></label>
                        <label>Bemerkung<textarea name="notes"><?= e($event['notes']) ?></textarea></label>
                        <div><button>Speichern</button></div>
                    </form>
                    <div class="toolbar">
                        <?php if ((int)$event['id'] !== $selectedId): ?><form method="post" action="/events/select"><input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>"><button class="secondary">Auswaehlen</button></form><?php endif; ?>
                        <form method="post" action="/events/delete" onsubmit="return confirm('Diesen Anlass wirklich loeschen? Kategorien, Teilnehmende und Zeiten werden ebenfalls geloescht.')"><input type="hidden" name="id" value="<?= (int)$event['id'] ?>"><button class="danger">Loeschen</button></form>
                    </div>
                </div><?php
            }
        });
        return;
    }

    if ($path === '/sport-results/team' && $method === 'POST') {
        requireWritableTenant('operator');
        $event = requireEvent();
        $stmt = db()->prepare(
            'INSERT INTO teams (event_id, name, group_label, contact_name, contact_email, notes)
             VALUES (:event_id, :name, :group_label, :contact_name, :contact_email, :notes)
             ON DUPLICATE KEY UPDATE group_label = VALUES(group_label), contact_name = VALUES(contact_name), contact_email = VALUES(contact_email), notes = VALUES(notes)'
        );
        $stmt->execute([
            'event_id' => (int)$event['id'],
            'name' => trim((string)$_POST['name']),
            'group_label' => trim((string)($_POST['group_label'] ?? '')),
            'contact_name' => trim((string)($_POST['contact_name'] ?? '')),
            'contact_email' => trim((string)($_POST['contact_email'] ?? '')),
            'notes' => trim((string)($_POST['notes'] ?? '')),
        ]);
        auditLog('sport.team_saved', (int)$event['tenant_id'], (int)(currentUser()['id'] ?? 0), 'event', (string)$event['id']);
        redirect('/sport-results', 'Team/Starter gespeichert.');
    }

    if ($path === '/sport-results/discipline' && $method === 'POST') {
        requireWritableTenant('operator');
        $event = requireEvent();
        $type = in_array((string)($_POST['discipline_type'] ?? 'custom'), ['match', 'attempts', 'points', 'bracket', 'custom'], true)
            ? (string)$_POST['discipline_type']
            : 'custom';
        db()->prepare(
            'INSERT INTO sport_disciplines (event_id, name, discipline_type, sort_order)
             VALUES (:event_id, :name, :discipline_type, :sort_order)'
        )->execute([
            'event_id' => (int)$event['id'],
            'name' => trim((string)$_POST['name']),
            'discipline_type' => $type,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
        ]);
        redirect('/sport-results', 'Disziplin/Wertung gespeichert.');
    }

    if ($path === '/sport-results/match' && $method === 'POST') {
        requireWritableTenant('operator');
        $event = requireEvent();
        $status = in_array((string)($_POST['status'] ?? 'scheduled'), ['scheduled', 'running', 'completed', 'cancelled'], true)
            ? (string)$_POST['status']
            : 'scheduled';
        db()->prepare(
            'INSERT INTO sport_matches (event_id, discipline_id, group_label, round_label, home_team_id, away_team_id, scheduled_at, home_score, away_score, status, notes)
             VALUES (:event_id, :discipline_id, :group_label, :round_label, :home_team_id, :away_team_id, :scheduled_at, :home_score, :away_score, :status, :notes)'
        )->execute([
            'event_id' => (int)$event['id'],
            'discipline_id' => (int)($_POST['discipline_id'] ?? 0) ?: null,
            'group_label' => trim((string)($_POST['group_label'] ?? '')),
            'round_label' => trim((string)($_POST['round_label'] ?? '')),
            'home_team_id' => (int)($_POST['home_team_id'] ?? 0) ?: null,
            'away_team_id' => (int)($_POST['away_team_id'] ?? 0) ?: null,
            'scheduled_at' => trim((string)($_POST['scheduled_at'] ?? '')) !== '' ? str_replace('T', ' ', trim((string)$_POST['scheduled_at'])) : null,
            'home_score' => trim((string)($_POST['home_score'] ?? '')) !== '' ? (float)$_POST['home_score'] : null,
            'away_score' => trim((string)($_POST['away_score'] ?? '')) !== '' ? (float)$_POST['away_score'] : null,
            'status' => $status,
            'notes' => trim((string)($_POST['notes'] ?? '')),
        ]);
        redirect('/sport-results', 'Begegnung/Kampf gespeichert.');
    }

    if ($path === '/sport-results/score' && $method === 'POST') {
        requireWritableTenant('operator');
        $event = requireEvent();
        $status = in_array((string)($_POST['status'] ?? 'valid'), ['pending', 'valid', 'dns', 'dnf', 'dsq'], true)
            ? (string)$_POST['status']
            : 'valid';
        db()->prepare(
            'INSERT INTO sport_scores (event_id, discipline_id, participant_id, team_id, score_value, score_text, rank_position, status, notes)
             VALUES (:event_id, :discipline_id, NULL, :team_id, :score_value, :score_text, :rank_position, :status, :notes)'
        )->execute([
            'event_id' => (int)$event['id'],
            'discipline_id' => (int)($_POST['discipline_id'] ?? 0) ?: null,
            'team_id' => (int)($_POST['team_id'] ?? 0) ?: null,
            'score_value' => trim((string)($_POST['score_value'] ?? '')) !== '' ? (float)$_POST['score_value'] : null,
            'score_text' => trim((string)($_POST['score_text'] ?? '')),
            'rank_position' => (int)($_POST['rank_position'] ?? 0) ?: null,
            'status' => $status,
            'notes' => trim((string)($_POST['notes'] ?? '')),
        ]);
        redirect('/sport-results', 'Resultat gespeichert.');
    }

    if ($path === '/sport-results' && $method === 'GET') {
        render('Wertung', function (): void {
            $event = requireEvent();
            $teamsStmt = db()->prepare('SELECT * FROM teams WHERE event_id = :event_id ORDER BY group_label, name');
            $teamsStmt->execute(['event_id' => (int)$event['id']]);
            $teams = $teamsStmt->fetchAll();
            $discStmt = db()->prepare('SELECT * FROM sport_disciplines WHERE event_id = :event_id ORDER BY sort_order, name');
            $discStmt->execute(['event_id' => (int)$event['id']]);
            $disciplines = $discStmt->fetchAll();
            ?><div class="panel"><p><?= e($event['sport_name'] ?? 'Sport') ?> · <?= e(scoringModes()[$event['scoring_mode']] ?? $event['scoring_mode']) ?></p></div>
            <div class="panel"><form method="post" action="/sport-results/team" class="grid">
                <label>Team / Starter<input required name="name"></label>
                <label>Gruppe / Kategorie<input name="group_label"></label>
                <label>Kontakt<input name="contact_name"></label>
                <label>Kontakt-E-Mail<input type="email" name="contact_email"></label>
                <label>Bemerkung<input name="notes"></label>
                <div><button>Team/Starter speichern</button></div>
            </form></div>
            <div class="panel"><form method="post" action="/sport-results/discipline" class="grid">
                <label>Disziplin / Wertung<input required name="name"></label>
                <label>Typ<select name="discipline_type"><option value="match">Spiel/Kampf</option><option value="attempts">Versuche</option><option value="points">Punkte</option><option value="bracket">K.-o.-Raster</option><option value="custom">Frei</option></select></label>
                <label>Sortierung<input type="number" name="sort_order" value="0"></label>
                <div><button>Disziplin speichern</button></div>
            </form></div>
            <div class="panel"><form method="post" action="/sport-results/match" class="grid">
                <label>Disziplin<select name="discipline_id"><option value="0">Keine</option><?php foreach ($disciplines as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></label>
                <label>Gruppe<input name="group_label"></label>
                <label>Runde<input name="round_label"></label>
                <label>Heim/Rot<select name="home_team_id"><option value="0">Offen</option><?php foreach ($teams as $team): ?><option value="<?= (int)$team['id'] ?>"><?= e($team['name']) ?></option><?php endforeach; ?></select></label>
                <label>Auswaerts/Weiss<select name="away_team_id"><option value="0">Offen</option><?php foreach ($teams as $team): ?><option value="<?= (int)$team['id'] ?>"><?= e($team['name']) ?></option><?php endforeach; ?></select></label>
                <label>Zeit<input type="datetime-local" name="scheduled_at"></label>
                <label>Score Heim<input type="number" step="0.01" name="home_score"></label>
                <label>Score Auswaerts<input type="number" step="0.01" name="away_score"></label>
                <label>Status<select name="status"><option value="scheduled">geplant</option><option value="running">laufend</option><option value="completed">fertig</option><option value="cancelled">abgesagt</option></select></label>
                <label>Bemerkung<input name="notes"></label>
                <div><button>Begegnung speichern</button></div>
            </form></div>
            <div class="panel"><form method="post" action="/sport-results/score" class="grid">
                <label>Disziplin<select name="discipline_id"><option value="0">Keine</option><?php foreach ($disciplines as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></label>
                <label>Team / Starter<select name="team_id"><option value="0">Keine</option><?php foreach ($teams as $team): ?><option value="<?= (int)$team['id'] ?>"><?= e($team['name']) ?></option><?php endforeach; ?></select></label>
                <label>Punkte/Wert<input type="number" step="0.01" name="score_value"></label>
                <label>Textresultat<input name="score_text"></label>
                <label>Rang<input type="number" name="rank_position"></label>
                <label>Status<select name="status"><option value="valid">valid</option><option value="pending">pending</option><option value="dns">dns</option><option value="dnf">dnf</option><option value="dsq">dsq</option></select></label>
                <label>Bemerkung<input name="notes"></label>
                <div><button>Resultat speichern</button></div>
            </form></div>
            <h2>Teams / Starter</h2><table><thead><tr><th>Name</th><th>Gruppe</th><th>Kontakt</th></tr></thead><tbody><?php
            foreach ($teams as $team) {
                echo '<tr><td>' . e($team['name']) . '</td><td>' . e($team['group_label']) . '</td><td>' . e($team['contact_name']) . '</td></tr>';
            }
            ?></tbody></table>
            <h2>Begegnungen / Kaempfe</h2><table><thead><tr><th>Zeit</th><th>Gruppe</th><th>Runde</th><th>Heim/Rot</th><th>Auswaerts/Weiss</th><th>Score</th><th>Status</th></tr></thead><tbody><?php
            $matches = db()->prepare(
                'SELECT m.*, h.name AS home_name, a.name AS away_name
                 FROM sport_matches m
                 LEFT JOIN teams h ON h.id = m.home_team_id
                 LEFT JOIN teams a ON a.id = m.away_team_id
                 WHERE m.event_id = :event_id ORDER BY m.scheduled_at, m.id'
            );
            $matches->execute(['event_id' => (int)$event['id']]);
            foreach ($matches as $m) {
                echo '<tr><td>' . e($m['scheduled_at']) . '</td><td>' . e($m['group_label']) . '</td><td>' . e($m['round_label']) . '</td><td>' . e($m['home_name']) . '</td><td>' . e($m['away_name']) . '</td><td>' . e($m['home_score'] !== null || $m['away_score'] !== null ? $m['home_score'] . ' : ' . $m['away_score'] : '') . '</td><td>' . e($m['status']) . '</td></tr>';
            }
            ?></tbody></table>
            <h2>Resultate</h2><table><thead><tr><th>Disziplin</th><th>Team/Starter</th><th>Wert</th><th>Text</th><th>Rang</th><th>Status</th></tr></thead><tbody><?php
            $scores = db()->prepare(
                'SELECT s.*, d.name AS discipline_name, t.name AS team_name
                 FROM sport_scores s
                 LEFT JOIN sport_disciplines d ON d.id = s.discipline_id
                 LEFT JOIN teams t ON t.id = s.team_id
                 WHERE s.event_id = :event_id ORDER BY d.sort_order, s.rank_position, s.id'
            );
            $scores->execute(['event_id' => (int)$event['id']]);
            foreach ($scores as $score) {
                echo '<tr><td>' . e($score['discipline_name']) . '</td><td>' . e($score['team_name']) . '</td><td>' . e($score['score_value']) . '</td><td>' . e($score['score_text']) . '</td><td>' . e((string)$score['rank_position']) . '</td><td>' . e($score['status']) . '</td></tr>';
            }
            ?></tbody></table><?php
        });
        return;
    }

    if ($path === '/categories' && $method === 'POST') {
        requireWritableTenant('operator');
        $eventId = (int)requireTimedEvent()['id'];
        $from = (int)$_POST['year_from'];
        $to = (int)$_POST['year_to'];
        $active = (int)($_POST['active'] ?? 0);
        $resolver = new CategoryResolver(db());
        $errors = $active ? $resolver->validateRange($eventId, $from, $to) : ($from > $to ? ['Jahrgang von darf nicht groesser sein als Jahrgang bis.'] : []);
        if ($errors !== []) {
            $_SESSION['flash'] = implode(' ', $errors);
            redirect('/categories');
        }
        $stmt = db()->prepare(
            'INSERT INTO categories (event_id, name, year_from, year_to, sort_order, active)
             VALUES (:event_id, :name, :year_from, :year_to, :sort_order, :active)'
        );
        $stmt->execute([
            'event_id' => $eventId,
            'name' => trim($_POST['name']),
            'year_from' => $from,
            'year_to' => $to,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'active' => $active,
        ]);
        redirect('/categories', 'Kategorie gespeichert.');
    }

    if ($path === '/categories/update' && $method === 'POST') {
        requireWritableTenant('operator');
        $eventId = (int)requireTimedEvent()['id'];
        $categoryId = (int)$_POST['id'];
        $from = (int)$_POST['year_from'];
        $to = (int)$_POST['year_to'];
        $active = (int)($_POST['active'] ?? 0);
        $resolver = new CategoryResolver(db());
        $errors = $active ? $resolver->validateRange($eventId, $from, $to, $categoryId) : ($from > $to ? ['Jahrgang von darf nicht groesser sein als Jahrgang bis.'] : []);
        if ($errors !== []) {
            $_SESSION['flash'] = implode(' ', $errors);
            redirect('/categories');
        }

        $stmt = db()->prepare(
            'UPDATE categories
             SET name = :name, year_from = :year_from, year_to = :year_to, sort_order = :sort_order, active = :active
             WHERE id = :id AND event_id = :event_id'
        );
        $stmt->execute([
            'name' => trim($_POST['name']),
            'year_from' => $from,
            'year_to' => $to,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'active' => $active,
            'id' => $categoryId,
            'event_id' => $eventId,
        ]);

        redirect('/categories', $stmt->rowCount() > 0 ? 'Kategorie aktualisiert.' : 'Kategorie unveraendert oder nicht gefunden.');
    }

    if ($path === '/categories/delete' && $method === 'POST') {
        requireWritableTenant('admin');
        $eventId = (int)requireTimedEvent()['id'];
        $stmt = db()->prepare('DELETE FROM categories WHERE id = :id AND event_id = :event_id');
        $stmt->execute([
            'id' => (int)$_POST['id'],
            'event_id' => $eventId,
        ]);

        redirect('/categories', $stmt->rowCount() > 0 ? 'Kategorie geloescht.' : 'Kategorie nicht gefunden.');
    }

    if ($path === '/categories' && $method === 'GET') {
        render('Jahrgangsgruppen', function (): void {
            $event = requireTimedEvent();
            $resolver = new CategoryResolver(db());
            foreach ($resolver->warningsForGaps((int)$event['id']) as $warning) {
                echo '<div class="warning">' . e($warning) . '</div>';
            }
            ?><div class="panel"><form method="post" class="grid">
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                <label>Name<input required name="name"></label>
                <label>Jahrgang von<input required type="number" name="year_from"></label>
                <label>Jahrgang bis<input required type="number" name="year_to"></label>
                <label>Sortierung<input type="number" name="sort_order" value="0"></label>
                <label>Aktiv<select name="active"><option value="1">Ja</option><option value="0">Nein</option></select></label>
                <div><button>Gruppe speichern</button></div>
            </form></div>
            <table><thead><tr><th>Name</th><th>Von</th><th>Bis</th><th>Sortierung</th><th>Wertungsgruppen</th><th>Aktiv</th><th>Aktion</th></tr></thead><tbody><?php
            foreach (categoriesForEvent((int)$event['id']) as $cat) {
                echo '<tr><td colspan="7"><form class="inline-form category-form" method="post" action="/categories/update"><input type="hidden" name="id" value="' . (int)$cat['id'] . '"><input type="hidden" name="event_id" value="' . (int)$event['id'] . '"><input required name="name" value="' . e($cat['name']) . '"><input required type="number" name="year_from" value="' . (int)$cat['year_from'] . '"><input required type="number" name="year_to" value="' . (int)$cat['year_to'] . '"><input type="number" name="sort_order" value="' . (int)$cat['sort_order'] . '"><span>' . e($cat['name']) . ' Maedchen<br>' . e($cat['name']) . ' Knaben</span><select name="active"><option value="1"' . ((int)$cat['active'] ? ' selected' : '') . '>Ja</option><option value="0"' . ((int)$cat['active'] ? '' : ' selected') . '>Nein</option></select><button>Speichern</button></form><form class="inline-form" method="post" action="/categories/delete" onsubmit="return confirm(\'Diese Jahrgangsgruppe wirklich loeschen? Zugeordnete Teilnehmende haben danach keine Kategorie mehr.\')"><input type="hidden" name="id" value="' . (int)$cat['id'] . '"><input type="hidden" name="event_id" value="' . (int)$event['id'] . '"><button class="danger">Loeschen</button></form></td></tr>';
            }
            ?></tbody></table><?php
        });
        return;
    }

    if ($path === '/participants' && $method === 'POST') {
        requireWritableTenant('operator');
        $event = requireTimedEvent();
        $_POST['event_id'] = (int)$event['id'];
        saveParticipant($_POST);
        redirect('/participants/create', 'Teilnehmer gespeichert.');
    }

    if ($path === '/participants/create' && $method === 'GET') {
        render('Teilnehmer erfassen', function (): void {
            $event = requireTimedEvent();
            $sheet = (new SheetNumberService(db()))->next((int)$event['id']);
            ?><div class="panel"><form method="post" action="/participants" class="grid">
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                <label>Laufzettel-ID<input required name="sheet_number" value="<?= e($sheet) ?>"></label>
                <label>Name<input required name="last_name" autofocus></label>
                <label>Vorname<input required name="first_name"></label>
                <label>Jahrgang<input required type="number" name="birth_year"></label>
                <label>Geschlecht<select name="gender"><option value="female">Maedchen</option><option value="male">Knabe</option></select></label>
                <label>Klasse<input name="school_class"></label>
                <label>Ort<input name="city"></label>
                <label>Bemerkung<textarea name="notes"></textarea></label>
                <div><button>Speichern und naechster Zettel</button></div>
            </form></div><?php
        });
        return;
    }

    if ($path === '/participants' && $method === 'GET') {
        render('Teilnehmer', function (): void {
            $event = requireTimedEvent();
            ?><div class="toolbar"><a class="button" href="/participants/create">Teilnehmer erfassen</a></div>
            <table><thead><tr><th>Zettel</th><th>Name</th><th>Vorname</th><th>Jg.</th><th>Geschlecht</th><th>Kategorie</th><th>Klasse</th><th>Ort</th></tr></thead><tbody><?php
            $stmt = db()->prepare(
                'SELECT p.*, c.name AS category_name FROM participants p LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.event_id = :event_id ORDER BY CAST(p.sheet_number AS UNSIGNED), p.sheet_number'
            );
            $stmt->execute(['event_id' => $event['id']]);
            foreach ($stmt as $p) {
                echo '<tr><td>' . e($p['sheet_number']) . '</td><td>' . e($p['last_name']) . '</td><td>' . e($p['first_name']) . '</td><td>' . (int)$p['birth_year'] . '</td><td>' . e($p['gender'] === 'female' ? 'Maedchen' : 'Knabe') . '</td><td>' . e($p['category_name'] ?: 'ohne Kategorie') . '</td><td>' . e($p['school_class']) . '</td><td>' . e($p['city']) . '</td></tr>';
            }
            ?></tbody></table><?php
        });
        return;
    }

    if ($path === '/results/save' && $method === 'POST') {
        requireWritableTenant('operator');
        $event = requireTimedEvent();
        $participantId = (int)$_POST['participant_id'];
        $stmt = db()->prepare('SELECT COUNT(*) FROM participants WHERE id = :id AND event_id = :event_id');
        $stmt->execute(['id' => $participantId, 'event_id' => $event['id']]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new InvalidArgumentException('Teilnehmer gehoert nicht zum ausgewaehlten Anlass.');
        }
        saveResult($participantId, $_POST, (int)$event['qualification_runs']);
        redirect('/results', 'Zeit gespeichert.');
    }

    if ($path === '/results' && $method === 'GET') {
        render('Qualifikationszeiten erfassen', function (): void {
            $event = requireTimedEvent();
            $q = trim((string)($_GET['q'] ?? ''));
            ?><form class="toolbar" method="get">
                <input name="q" value="<?= e($q) ?>" placeholder="Laufzettel-ID, Name, Vorname, Klasse">
                <button>Suchen</button>
            </form><?php
            $sql = 'SELECT p.*, c.name AS category_name, r.run1_time_tenths, r.run2_time_tenths, r.best_qualification_time_tenths, r.qualification_status, r.notes AS result_notes
                    FROM participants p
                    LEFT JOIN categories c ON c.id = p.category_id
                    LEFT JOIN results r ON r.participant_id = p.id
                    WHERE p.event_id = :event_id';
            $params = ['event_id' => $event['id']];
            if ($q !== '') {
                $sql .= ' AND (p.sheet_number LIKE :q OR p.last_name LIKE :q OR p.first_name LIKE :q OR p.school_class LIKE :q)';
                $params['q'] = '%' . $q . '%';
            }
            $sql .= ' ORDER BY CAST(p.sheet_number AS UNSIGNED), p.sheet_number LIMIT 80';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt as $p) {
                ?><div class="panel">
                    <h2><?= e($p['sheet_number']) ?> · <?= e($p['last_name']) ?> <?= e($p['first_name']) ?></h2>
                    <p class="muted"><?= e($p['category_name'] ?: 'ohne Kategorie') ?> · Beste Zeit: <?= e(TimeParser::format($p['best_qualification_time_tenths'] !== null ? (int)$p['best_qualification_time_tenths'] : null)) ?></p>
                    <form method="post" action="/results/save" class="grid">
                        <input type="hidden" name="participant_id" value="<?= (int)$p['id'] ?>">
                        <label>Lauf 1<input name="run1_time" value="<?= e(TimeParser::format($p['run1_time_tenths'] !== null ? (int)$p['run1_time_tenths'] : null)) ?>"></label>
                        <?php if ((int)$event['qualification_runs'] > 1): ?><label>Lauf 2<input name="run2_time" value="<?= e(TimeParser::format($p['run2_time_tenths'] !== null ? (int)$p['run2_time_tenths'] : null)) ?>"></label><?php endif; ?>
                        <label>Status<select name="qualification_status">
                            <?php foreach (['no_time', 'valid', 'dns', 'dnf', 'dsq'] as $status): ?>
                                <option value="<?= e($status) ?>" <?= $p['qualification_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label>Bemerkung<input name="result_notes" value="<?= e($p['result_notes']) ?>"></label>
                        <div><button>Zeit speichern</button></div>
                    </form>
                </div><?php
            }
        });
        return;
    }

    if ($path === '/quick-entry' && $method === 'POST') {
        requireWritableTenant('operator');
        $event = requireTimedEvent();
        $_POST['event_id'] = (int)$event['id'];
        $participantId = saveParticipant($_POST);
        saveResult($participantId, $_POST, (int)$event['qualification_runs']);
        redirect('/quick-entry', 'Schnellerfassung gespeichert.');
    }

    if ($path === '/quick-entry' && $method === 'GET') {
        render('Schnellerfassung', function (): void {
            $event = requireTimedEvent();
            $sheet = (new SheetNumberService(db()))->next((int)$event['id']);
            ?><div class="panel"><form method="post" class="grid">
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                <label>Laufzettel-ID<input required name="sheet_number" value="<?= e($sheet) ?>"></label>
                <label>Name<input required name="last_name" autofocus></label>
                <label>Vorname<input required name="first_name"></label>
                <label>Jahrgang<input required type="number" name="birth_year"></label>
                <label>Geschlecht<select name="gender"><option value="female">Maedchen</option><option value="male">Knabe</option></select></label>
                <label>Klasse<input name="school_class"></label>
                <label>Ort<input name="city"></label>
                <label>Lauf 1<input name="run1_time" placeholder="1:23.4"></label>
                <?php if ((int)$event['qualification_runs'] > 1): ?><label>Lauf 2<input name="run2_time" placeholder="83.4"></label><?php endif; ?>
                <div><button>Speichern und naechster Zettel</button></div>
            </form></div><?php
        });
        return;
    }

    if ($path === '/rankings/qualification' && $method === 'GET') {
        render('Qualifikationsrangliste', function (): void {
            $event = requireTimedEvent();
            $groups = (new RankingService(db()))->qualificationRows((int)$event['id']);
            ?><div class="toolbar"><a class="button light" href="/rankings/pdf?type=qualification">Druck/PDF</a></div><?php
            foreach ($groups as $group => $rows) {
                echo '<h2>' . e($group) . '</h2>';
                renderRankingTable($rows, $event);
            }
        });
        return;
    }

    if ($path === '/finalists/apply' && $method === 'POST') {
        requireWritableTenant('operator');
        $event = requireFinalEvent();
        (new FinalistService(db(), new RankingService(db())))->applyProposal((int)$event['id'], (int)$event['finalists_per_group']);
        redirect('/finalists', 'Finalistenvorschlag angewendet.');
    }

    if ($path === '/finalists/confirm' && $method === 'POST') {
        requireWritableTenant('operator');
        $event = requireFinalEvent();
        (new FinalistService(db(), new RankingService(db())))->confirm((int)$event['id'], array_map('intval', $_POST['participant_ids'] ?? []));
        redirect('/finalists?confirmed=1', 'Finalisten bestaetigt.');
    }

    if ($path === '/finalists' && $method === 'GET') {
        render('Finalisten', function (): void {
            $event = requireFinalEvent();
            $proposal = (new FinalistService(db(), new RankingService(db())))->propose((int)$event['id'], (int)$event['finalists_per_group']);
            ?><div class="toolbar">
                <form method="post" action="/finalists/apply"><button>Top <?= (int)$event['finalists_per_group'] ?> vorschlagen</button></form>
                <a class="button light" href="/finalists/pdf">Bestaetigte drucken/PDF</a>
            </div><?php
            if (($_GET['confirmed'] ?? '') === '1') {
                echo '<div class="toolbar"><a class="button" href="/finalists/pdf">Finalistenliste jetzt drucken</a></div>';
            }
            ?><form method="post" action="/finalists/confirm"><?php
            foreach ($proposal['groups'] as $group => $data) {
                echo '<h2>' . e($group) . '</h2>';
                if ($data['warning']) {
                    echo '<div class="warning">' . e($data['warning']) . '</div>';
                }
                echo '<table><thead><tr><th>Bestaetigen</th><th>Name</th><th>Vorname</th><th>Qualizeit</th><th>Hinweis</th></tr></thead><tbody>';
                foreach ($data['rows'] as $row) {
                    $tie = in_array($row, $data['tie_rows'], true) && count($data['tie_rows']) > 1;
                    echo '<tr><td><input type="checkbox" name="participant_ids[]" value="' . (int)$row['id'] . '" checked></td><td>' . e($row['last_name']) . '</td><td>' . e($row['first_name']) . '</td><td>' . e(TimeParser::format((int)$row['best_qualification_time_tenths'])) . '</td><td>' . ($tie ? 'Gleichstand pruefen' : '') . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            ?><div class="toolbar"><button>Auswahl bestaetigen</button></div></form><?php
        });
        return;
    }

    if ($path === '/finalists/pdf' && $method === 'GET') {
        $event = requireFinalEvent();
        $groups = confirmedFinalistGroups((int)$event['id']);
        $html = printablePage('Bestaetigte Finalisten', function () use ($event, $groups): void {
            echo '<p>' . e($event['name']) . ' - ' . e(formatEventDate((string)$event['event_date'])) . '</p>';
            renderConfirmedFinalists($groups);
        });
        PdfService::output($html, eventFileName($event, 'finalistenliste', 'pdf'));
        return;
    }

    if ($path === '/final-results/save' && $method === 'POST') {
        requireWritableTenant('operator');
        $event = requireFinalEvent();
        foreach ($_POST['final'] ?? [] as $participantId => $data) {
            $time = TimeParser::parse($data['time'] ?? null);
            $status = $time === null ? ($data['status'] ?? 'qualified') : 'valid';
            if (!in_array($status, ['qualified', 'valid', 'dns', 'dnf', 'dsq'], true)) {
                $status = 'qualified';
            }
            $stmt = db()->prepare(
                'UPDATE results r JOIN participants p ON p.id = r.participant_id
                 SET r.final_time_tenths = :time, r.final_status = :status
                 WHERE r.participant_id = :id AND p.event_id = :event_id'
            );
            $stmt->execute(['time' => $time, 'status' => $status, 'id' => (int)$participantId, 'event_id' => $event['id']]);
        }
        redirect('/final-results', 'Finalzeiten gespeichert.');
    }

    if ($path === '/final-results' && $method === 'GET') {
        render('Finalzeiten erfassen', function (): void {
            $event = requireFinalEvent();
            $stmt = db()->prepare(
                'SELECT p.*, c.name AS category_name, r.final_time_tenths, r.final_status
                 FROM participants p JOIN categories c ON c.id = p.category_id JOIN results r ON r.participant_id = p.id
                 WHERE p.event_id = :event_id AND r.finalist_confirmed = 1
                 ORDER BY c.sort_order, p.gender, p.last_name, p.first_name'
            );
            $stmt->execute(['event_id' => $event['id']]);
            ?><form method="post" action="/final-results/save"><table><thead><tr><th>Gruppe</th><th>Name</th><th>Vorname</th><th>Finalzeit</th><th>Status</th></tr></thead><tbody><?php
            foreach ($stmt as $row) {
                ?><tr>
                    <td><?= e($row['category_name']) ?> <?= e($row['gender'] === 'female' ? 'Maedchen' : 'Knaben') ?></td>
                    <td><?= e($row['last_name']) ?></td>
                    <td><?= e($row['first_name']) ?></td>
                    <td><input name="final[<?= (int)$row['id'] ?>][time]" value="<?= e(TimeParser::format($row['final_time_tenths'] !== null ? (int)$row['final_time_tenths'] : null)) ?>"></td>
                    <td><select name="final[<?= (int)$row['id'] ?>][status]">
                        <?php foreach (['qualified', 'valid', 'dns', 'dnf', 'dsq'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $row['final_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr><?php
            }
            ?></tbody></table><div class="toolbar"><button>Finalzeiten speichern</button></div></form><?php
        });
        return;
    }

    if ($path === '/rankings' && $method === 'GET') {
        render('Endrangliste', function (): void {
            $event = requireTimedEvent();
            $finalEnabled = (int)$event['final_enabled'] === 1;
            $groups = (new RankingService(db()))->endRows((int)$event['id'], $finalEnabled);
            ?><div class="toolbar"><a class="button light" href="/rankings/pdf?type=final">Druck/PDF</a><a class="button light" href="/export/csv">CSV</a></div><?php
            foreach ($groups as $group => $rows) {
                echo '<h2>' . e($group) . '</h2>';
                renderRankingTable($rows, $event, $finalEnabled);
            }
        });
        return;
    }

    if ($path === '/rankings/pdf' && $method === 'GET') {
        $event = requireTimedEvent();
        $type = $_GET['type'] ?? 'final';
        $service = new RankingService(db());
        $finalEnabled = (int)$event['final_enabled'] === 1;
        $isQualification = $type === 'qualification';
        $groups = $isQualification ? $service->qualificationRows((int)$event['id']) : $service->endRows((int)$event['id'], $finalEnabled);
        $html = printablePage($isQualification ? 'Qualifikationsrangliste' : 'Endrangliste', function () use ($event, $groups, $isQualification, $finalEnabled): void {
            echo '<p>' . e($event['name']) . ' · ' . e(formatEventDate((string)$event['event_date'])) . ' · ' . e($event['distance_label']) . '</p>';
            foreach ($groups as $group => $rows) {
                echo '<h2>' . e($group) . '</h2>';
                renderRankingTable($rows, $event, !$isQualification && $finalEnabled);
            }
        });
        PdfService::output($html, eventFileName($event, $isQualification ? 'qualifikation' : 'endrangliste', 'pdf'));
        return;
    }

    if ($path === '/sheets/pdf' && $method === 'GET') {
        $event = requireTimedEvent();
        $from = max(1, (int)($_GET['from'] ?? 1));
        $to = max($from, (int)($_GET['to'] ?? 20));
        $html = printablePage('Laufzettel', function () use ($event, $from, $to): void {
            echo '<form class="toolbar no-print" method="get"><input type="number" name="from" value="' . $from . '"><input type="number" name="to" value="' . $to . '"><button>Bereich anzeigen</button></form>';
            echo '<div class="sheet-grid">';
            for ($i = $from; $i <= $to; $i++) {
                $sheet = str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                renderRunSheet($event, $sheet);
            }
            echo '</div>';
        });
        PdfService::output($html, eventFileName($event, 'laufzettel', 'pdf'), 'landscape');
        return;
    }

    if ($path === '/export/csv' && $method === 'GET') {
        $event = requireTimedEvent();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . eventFileName($event, 'endrangliste', 'csv') . '"');
        $out = fopen('php://output', 'w');
        $header = ['Rang', 'Name', 'Vorname', 'Jahrgang', 'Geschlecht', 'Klasse', 'Ort', 'Kategorie', 'Lauf 1'];
        if ((int)$event['qualification_runs'] > 1) {
            $header[] = 'Lauf 2';
        }
        $header[] = 'Beste Qualifikation';
        if ((int)$event['final_enabled'] === 1) {
            array_push($header, 'Finalist', 'Finalzeit');
        }
        $header[] = 'Wertungsstatus';
        fputcsv($out, $header, ';');
        $finalEnabled = (int)$event['final_enabled'] === 1;
        foreach ((new RankingService(db()))->endRows((int)$event['id'], $finalEnabled) as $group => $rows) {
            foreach ($rows as $row) {
                $csvRow = [
                    $row['rank'], $row['last_name'], $row['first_name'], $row['birth_year'],
                    $row['gender'] === 'female' ? 'Maedchen' : 'Knabe', $row['school_class'], $row['city'],
                    $row['category_name'], TimeParser::format($row['run1_time_tenths'] !== null ? (int)$row['run1_time_tenths'] : null),
                ];
                if ((int)$event['qualification_runs'] > 1) {
                    $csvRow[] = TimeParser::format($row['run2_time_tenths'] !== null ? (int)$row['run2_time_tenths'] : null);
                }
                $csvRow[] = TimeParser::format((int)$row['best_qualification_time_tenths']);
                if ((int)$event['final_enabled'] === 1) {
                    $csvRow[] = (int)$row['finalist_confirmed'] === 1 ? 'ja' : 'nein';
                    $csvRow[] = TimeParser::format($row['final_time_tenths'] !== null ? (int)$row['final_time_tenths'] : null);
                }
                $csvRow[] = $row['ranking_segment'] ?? $row['qualification_status'];
                fputcsv($out, $csvRow, ';');
            }
        }
        fclose($out);
        return;
    }

    http_response_code(404);
    render('Nicht gefunden', static fn () => print '<div class="error">Route nicht gefunden.</div>');
} catch (Throwable $e) {
    http_response_code(500);
    renderErrorPage($e->getMessage());
}
