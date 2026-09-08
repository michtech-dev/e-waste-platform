<?php
require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Divisions administratives";
$activeNav = 'divisions';
$message = null; $error = null;

// ---- Import CSV (zones + collines) ----
// Format attendu (avec en-tête) : province,commune,zone,colline,type
// type = "colline" ou "quartier". Une ligne par colline/quartier.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv' && !empty($_FILES['csv_file']['tmp_name'])) {
    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $header = fgetcsv($handle);
    $imported = 0; $skipped = 0;

    if ($handle) {
        $pdo->beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 5) { $skipped++; continue; }
                [$provinceName, $communeName, $zoneName, $collineName, $type] = array_map('trim', $row);
                $type = in_array(strtolower($type), ['colline', 'quartier'], true) ? strtolower($type) : 'colline';
                if ($provinceName === '' || $communeName === '' || $zoneName === '' || $collineName === '') {
                    $skipped++; continue;
                }

                // Province : trouver ou créer
                $p = $pdo->prepare('SELECT id FROM provinces WHERE name = :n');
                $p->execute(['n' => $provinceName]);
                $provinceId = $p->fetchColumn();
                if (!$provinceId) {
                    $ins = $pdo->prepare('INSERT INTO provinces (name) VALUES (:n) RETURNING id');
                    $ins->execute(['n' => $provinceName]);
                    $provinceId = $ins->fetchColumn();
                }

                // Commune : trouver ou créer
                $c = $pdo->prepare('SELECT id FROM communes WHERE province_id = :pid AND name = :n');
                $c->execute(['pid' => $provinceId, 'n' => $communeName]);
                $communeId = $c->fetchColumn();
                if (!$communeId) {
                    $ins = $pdo->prepare('INSERT INTO communes (province_id, name) VALUES (:pid, :n) RETURNING id');
                    $ins->execute(['pid' => $provinceId, 'n' => $communeName]);
                    $communeId = $ins->fetchColumn();
                }

                // Zone : trouver ou créer
                $z = $pdo->prepare('SELECT id FROM zones WHERE commune_id = :cid AND name = :n');
                $z->execute(['cid' => $communeId, 'n' => $zoneName]);
                $zoneId = $z->fetchColumn();
                if (!$zoneId) {
                    $ins = $pdo->prepare('INSERT INTO zones (commune_id, name) VALUES (:cid, :n) RETURNING id');
                    $ins->execute(['cid' => $communeId, 'n' => $zoneName]);
                    $zoneId = $ins->fetchColumn();
                }

                // Colline/quartier : insérer si absent
                $col = $pdo->prepare('SELECT id FROM collines WHERE zone_id = :zid AND name = :n');
                $col->execute(['zid' => $zoneId, 'n' => $collineName]);
                if (!$col->fetchColumn()) {
                    $ins = $pdo->prepare('INSERT INTO collines (zone_id, name, type) VALUES (:zid, :n, :t)');
                    $ins->execute(['zid' => $zoneId, 'n' => $collineName, 't' => $type]);
                    $imported++;
                } else {
                    $skipped++;
                }
            }
            $pdo->commit();
            $message = "{$imported} collines/quartiers importés, {$skipped} lignes ignorées (déjà existantes ou incomplètes).";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Échec de l'import : " . $e->getMessage();
        }
        fclose($handle);
    }
}

$provinces = $pdo->query(
    "SELECT p.id, p.name, p.chef_lieu,
            COUNT(DISTINCT c.id) AS communes_count,
            COUNT(DISTINCT z.id) AS zones_count,
            COUNT(DISTINCT col.id) AS collines_count
     FROM provinces p
     LEFT JOIN communes c ON c.province_id = p.id
     LEFT JOIN zones z ON z.commune_id = c.id
     LEFT JOIN collines col ON col.zone_id = z.id
     GROUP BY p.id ORDER BY p.name"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <h2 style="margin-top:0; font-size:15px;">Importer ou corriger des zones et collines/quartiers</h2>
  <p style="color:var(--ink-soft); font-size:13px;">
    Les 5 provinces, 42 communes, 451 zones et 3044 collines/quartiers officiels
    (Loi organique n°1/05 du 16 mars 2023) sont normalement déjà chargés via
    <code>database/divisions_full_seed.sql</code>. Utilise ce formulaire uniquement pour
    corriger une entrée ou ajouter des divisions manquantes, via un CSV avec les
    colonnes dans cet ordre exact :
  </p>
  <p class="mono" style="font-size:12.5px; background:var(--bg); padding:8px 12px; border-radius:6px;">
    province,commune,zone,colline,type
  </p>
  <p style="color:var(--ink-soft); font-size:13px;">
    <code>type</code> vaut <code>colline</code> ou <code>quartier</code>. Une ligne par colline/quartier
    (les provinces/communes/zones sont créées automatiquement si elles n'existent pas encore).
    Ex : <span class="mono">BUHUMUZA,Butaganzwa,Bisinde,Bisinde,colline</span>
  </p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="import_csv">
    <div class="form-row">
      <label>Fichier CSV</label>
      <input type="file" name="csv_file" accept=".csv" required>
    </div>
    <button type="submit" class="btn">Importer</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0; font-size:15px;">Provinces et couverture actuelle</h2>
  <table>
    <thead><tr><th>Province</th><th>Chef-lieu</th><th>Communes</th><th>Zones importées</th><th>Collines/quartiers importés</th></tr></thead>
    <tbody>
      <?php foreach ($provinces as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['name']) ?></td>
        <td><?= htmlspecialchars($p['chef_lieu'] ?? '—') ?></td>
        <td class="mono"><?= $p['communes_count'] ?></td>
        <td class="mono"><?= $p['zones_count'] ?></td>
        <td class="mono"><?= $p['collines_count'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
