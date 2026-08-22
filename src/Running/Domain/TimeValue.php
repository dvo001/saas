<?php
declare(strict_types=1);
namespace App\Running\Domain;
final readonly class TimeValue
{
    public static function parse(string $input,TimePrecision $precision):int { $input=trim(str_replace(',','.',$input));if($input===''||str_starts_with($input,'-')){throw new \DomainException('Zeit fehlt oder ist negativ.');}$parts=explode(':',$input);if(count($parts)>2){throw new \DomainException('Ungültiges Zeitformat.');}$secondsPart=array_pop($parts);$minutes=$parts===[]?0:(int)$parts[0];if(!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/',(string)$secondsPart,$m)||$minutes<0){throw new \DomainException('Zeitformat ungültig.');}$seconds=(int)$m[1];if($parts!==[]&&$seconds>=60){throw new \DomainException('Sekunden müssen kleiner als 60 sein.');}$digits=$precision===TimePrecision::Tenths?1:2;$fraction=str_pad(substr($m[2]??'',0,$digits),$digits,'0');return (($minutes*60)+$seconds)*$precision->unitsPerSecond()+(int)$fraction; }
    public static function format(int $units,TimePrecision $precision):string { if($units<0){throw new \DomainException('Zeit darf nicht negativ sein.');}$per=$precision->unitsPerSecond();$seconds=intdiv($units,$per);$fraction=$units%$per;return sprintf('%02d:%02d.%0'.($precision===TimePrecision::Tenths?1:2).'d',intdiv($seconds,60),$seconds%60,$fraction); }
}
