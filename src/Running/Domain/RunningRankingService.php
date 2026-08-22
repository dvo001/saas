<?php
declare(strict_types=1);
namespace App\Running\Domain;
final readonly class RunningRankingService
{
    /**
     * @param array<int, array{id: int|string, times: list<int>}> $rows
     * @return list<array{id: int|string, times: list<int>, rank: int}>
     */
    public function qualification(array $rows):array { $rankable=[];foreach($rows as $row){$times=$row['times'];sort($times,SORT_NUMERIC);if($times!==[]){$row['times']=$times;$rankable[]=$row;}}usort($rankable,static fn(array $a,array $b):int=>$a['times']<=>$b['times']);$rank=0;$previous=null;foreach($rankable as $index=>&$row){if($previous===null||$row['times']!==$previous){$rank=$index+1;$previous=$row['times'];}$row['rank']=$rank;}unset($row);return $rankable; }
    /**
     * @param list<array{id: int|string, times: list<int>, rank?: int}> $ranked
     * @return list<int|string>
     */
    public function finalists(array $ranked,int $limit):array { if($limit<1||$ranked===[]){return [];}$qualified=array_slice($ranked,0,$limit);if(count($ranked)>$limit){$boundary=$ranked[$limit-1]['times'];for($i=$limit,$count=count($ranked);$i<$count&&$ranked[$i]['times']===$boundary;++$i){$qualified[]=$ranked[$i];}}return array_column($qualified,'id'); }
    /**
     * @param array<int, array{id: int|string, time: ?int, status: RunStatus}> $rows
     * @return list<array{id: int|string, time: ?int, status: RunStatus, rank: ?int}>
     */
    public function final(array $rows):array { $priority=[RunStatus::Valid->value=>0,RunStatus::Dnf->value=>1,RunStatus::Dns->value=>2,RunStatus::Dsq->value=>3];usort($rows,static fn(array $a,array $b):int=>[$priority[$a['status']->value],$a['time']??PHP_INT_MAX]<=>[$priority[$b['status']->value],$b['time']??PHP_INT_MAX]);$rank=0;$position=0;$previous=null;foreach($rows as &$row){++$position;if($row['status']!==RunStatus::Valid||$row['time']===null){$row['rank']=null;continue;}if($previous===null||$row['time']!==$previous){$rank=$position;$previous=$row['time'];}$row['rank']=$rank;}unset($row);return $rows; }
}
