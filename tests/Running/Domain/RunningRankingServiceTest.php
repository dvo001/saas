<?php
declare(strict_types=1);
namespace App\Tests\Running\Domain;
use App\Running\Domain\RunStatus;use App\Running\Domain\RunningRankingService;use App\Running\Domain\TimePrecision;use App\Running\Domain\TimeValue;use PHPUnit\Framework\TestCase;
final class RunningRankingServiceTest extends TestCase
{
 public function testTimeUsesIntegerPrecision():void{self::assertSame(834,TimeValue::parse('1:23.4',TimePrecision::Tenths));self::assertSame(8345,TimeValue::parse('83.45',TimePrecision::Hundredths));self::assertSame('01:23.45',TimeValue::format(8345,TimePrecision::Hundredths));}
 public function testQualificationUsesAllTimesForTieBreak():void{$ranked=(new RunningRankingService())->qualification([['id'=>1,'times'=>[120,130]],['id'=>2,'times'=>[120,125]],['id'=>3,'times'=>[120,125]]]);self::assertSame([2,3,1],array_column($ranked,'id'));self::assertSame([1,1,3],array_column($ranked,'rank'));self::assertSame([2,3],(new RunningRankingService())->finalists($ranked,1));}
 public function testFinalIgnoresQualificationAndSharesRanks():void{$rows=(new RunningRankingService())->final([['id'=>1,'time'=>100,'status'=>RunStatus::Valid],['id'=>2,'time'=>100,'status'=>RunStatus::Valid],['id'=>3,'time'=>null,'status'=>RunStatus::Dnf]]);self::assertSame([1,1,null],array_column($rows,'rank'));}
}
