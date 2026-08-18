<?php
/** Ancienne page « Récapitulatif » — désormais fusionnée dans « Repas ». */
require __DIR__ . '/bootstrap.php';
require_login();
$qs = $_SERVER['QUERY_STRING'] ?? '';
redirect('repas.php' . ($qs ? ('?' . $qs) : ''));
