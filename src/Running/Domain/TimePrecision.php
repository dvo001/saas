<?php
declare(strict_types=1);
namespace App\Running\Domain;
enum TimePrecision:string { case Tenths='tenths'; case Hundredths='hundredths'; public function unitsPerSecond():int{return $this===self::Tenths?10:100;} }
