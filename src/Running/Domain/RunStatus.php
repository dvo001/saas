<?php
declare(strict_types=1);
namespace App\Running\Domain;
enum RunStatus:string { case Valid='valid'; case Dns='dns'; case Dnf='dnf'; case Dsq='dsq'; }
