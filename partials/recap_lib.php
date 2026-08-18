<?php
/** Calcul du récapitulatif des repas par catégorie d'âge (partagé par repas.php et pdf.php). */

/**
 * Compte les présences aux repas sur une période, ventilées par catégorie d'âge.
 *
 * @return array<string, array{
 *   midi: array<string,int>,   // clé = catégorie ('' = non renseigné) + 'total'
 *   soir: array<string,int>,
 *   present: int               // personnes sur place (au moins un repas ce jour-là)
 * }>  indexé par jour YYYY-MM-DD
 */
function calcul_recap(string $from, string $to): array
{
    $pdo = db();

    // Nombre de participants par réservation et par catégorie.
    $catParResa = []; // [rid][categorie] = n
    $stmt = $pdo->query('SELECT reservation_id, categorie, COUNT(*) n FROM participants GROUP BY reservation_id, categorie');
    foreach ($stmt as $row) {
        $catParResa[(int) $row['reservation_id']][$row['categorie']] = (int) $row['n'];
    }

    $categories = array_keys(LC_CATEGORIES); // ['bebe','enfant','ado','adulte','']

    // Squelette des jours.
    $days = [];
    foreach (jours_entre($from, $to) as $j) {
        $init = array_fill_keys($categories, 0);
        $init['total'] = 0;
        $days[$j] = ['midi' => $init, 'soir' => $init, 'present' => 0, '_rids' => []];
    }

    // Présences effectives sur la période.
    $stmt = $pdo->prepare('
        SELECT reservation_id, jour, repas
        FROM repas
        WHERE jour BETWEEN :from AND :to AND present = 1
    ');
    $stmt->execute([':from' => $from, ':to' => $to]);
    foreach ($stmt as $r) {
        $j = $r['jour'];
        if (!isset($days[$j])) { continue; }
        $rid = (int) $r['reservation_id'];
        $meal = $r['repas'];
        foreach (($catParResa[$rid] ?? []) as $cat => $n) {
            $days[$j][$meal][$cat] += $n;
            $days[$j][$meal]['total'] += $n;
        }
        $days[$j]['_rids'][$rid] = true;
    }

    // Personnes sur place = somme des participants des réservations présentes ce jour.
    foreach ($days as $j => &$d) {
        $sur = 0;
        foreach (array_keys($d['_rids']) as $rid) {
            $sur += array_sum($catParResa[$rid] ?? []);
        }
        $d['present'] = $sur;
        unset($d['_rids']);
    }
    unset($d);

    return $days;
}
