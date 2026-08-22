<?php
declare(strict_types=1);
namespace App\Tests\Running\Application;
use App\Running\Application\RunningPdfService;use PHPUnit\Framework\TestCase;
final class RunningPdfServiceTest extends TestCase
{
 public function testCreatesRealPdfForSheets():void{$run=['event'=>['name'=>'Testlauf','status'=>'running'],'settings'=>['qualification_runs'=>2],'participants'=>[['start_number'=>1,'first_name'=>'Ada','last_name'=>'Lovelace','category_name'=>'U12']],'participants_by_id'=>[],'qualification'=>[],'finals'=>[]];self::assertStringStartsWith('%PDF-', (new RunningPdfService())->create($run,'sheets'));}
 public function testCreatesFinalRankingWithFormattedTime():void{$run=['event'=>['name'=>'Testlauf','status'=>'running'],'settings'=>['time_precision'=>'hundredths'],'participants_by_id'=>['p1'=>['first_name'=>'Ada','last_name'=>'Lovelace']],'qualification'=>[],'finals'=>['U12|female'=>[['id'=>'p1','time'=>1234,'status'=>\App\Running\Domain\RunStatus::Valid,'rank'=>1]]]];self::assertStringStartsWith('%PDF-', (new RunningPdfService())->create($run,'final'));}
 public function testCancelledEventCannotCreateOfficialPdf():void{$this->expectException(\DomainException::class);(new RunningPdfService())->create(['event'=>['status'=>'cancelled']],'sheets');}
}
