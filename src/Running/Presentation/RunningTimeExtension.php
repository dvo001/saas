<?php
declare(strict_types=1);
namespace App\Running\Presentation;
use App\Running\Domain\TimePrecision;use App\Running\Domain\TimeValue;use Twig\Extension\AbstractExtension;use Twig\TwigFilter;
final class RunningTimeExtension extends AbstractExtension
{
    /** @return list<TwigFilter> */ public function getFilters():array{return [new TwigFilter('running_time',$this->format(...))];}
    public function format(?int $units,string $precision):string{return $units===null?'–':TimeValue::format($units,TimePrecision::from($precision));}
}
