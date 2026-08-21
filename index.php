<?php

declare(strict_types=1);

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "Dieses Verzeichnis ist nicht der Web-DocumentRoot. Bitte public/ konfigurieren.\n";
