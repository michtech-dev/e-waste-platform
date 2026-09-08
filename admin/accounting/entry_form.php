<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Saisie d\'écriture';
$activeNav = 'acc_entry_form';

$editId = (int)($_GET['id'] ?? 0);
$entry = $editId ? AccountingEntry::find($editId) : null;
if ($editId && !$entry) {
    acc_flash_set('', 'Écriture introuvable.');
    header('Location: entries.php');
    exit;
}
if ($entry && $entry['status'] === 'validated') {
    acc_flash_set('', 'Une écriture validée ne peut plus être modifiée.');
    header('Location: print.php?type=entry&id=' . $editId);
    exit;
}

$periods = AccountingPeriod::all();
$journals = AccountingJournal::all(true);
$tiers = AccountingThirdParty::search(['is_active' => '']);
$accounts = AccountingAccount::imputable();

// Exercice/journal par défaut
$curPeriod = AccountingPeriod::current();
$dfltPeriodId = $entry['period_id'] ?? ($curPeriod['id'] ?? 0);
$dfltJournalId = $entry['journal_id'] ?? (int)($journals[0]['id'] ?? 0);
$dfltNumber = $dfltPeriodId && $dfltJournalId
    ? AccountingEntry::nextNumber((int)$dfltPeriodId, (int)$dfltJournalId)
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reconstruction des lignes depuis les tableaux du formulaire
    $rawLines = [];
    $accIds   = $_POST['line_account_id'] ?? [];
    $thirdIds = $_POST['line_third_id'] ?? [];
    $labels   = $_POST['line_label'] ?? [];
    $debits   = $_POST['line_debit'] ?? [];
    $credits  = $_POST['line_credit'] ?? [];
    $dues     = $_POST['line_due'] ?? [];
    foreach ($accIds as $i => $accId) {
        $rawLines[] = [
            'account_id'     => $accId,
            'third_party_id' => $thirdIds[$i] ?? 0,
            'label'          => $labels[$i] ?? '',
            'debit'          => str_replace(["\xC2\xA0", ' '], '', (string)($debits[$i] ?? '')),
            'credit'         => str_replace(["\xC2\xA0", ' '], '', (string)($credits[$i] ?? '')),
            'due_date'       => $dues[$i] ?? '',
        ];
    }
    $status = ($_POST['save_status'] ?? '') === 'validated' ? 'validated' : 'draft';

    if (acc_handle(function () use ($editId, $status, $rawLines, $currentAdmin) {
        $header = [
            'period_id'      => (int)$_POST['period_id'],
            'journal_id'     => (int)$_POST['journal_id'],
            'entry_number'   => $_POST['entry_number'] ?? '',
            'entry_date'     => $_POST['entry_date'] ?? '',
            'reference'      => $_POST['reference'] ?? '',
            'third_party_id' => (int)($_POST['third_party_id'] ?? 0),
            'label'          => $_POST['label'] ?? '',
            'status'         => $status,
            'created_by'     => (int)$currentAdmin['id'],
        ];
        if ($editId) {
            AccountingEntry::update($editId, $header, $rawLines);
            if ($status === 'validated') {
                AccountingEntry::setStatus($editId, 'validated');
                acc_flash_set('Écriture mise à jour et validée.');
            } else {
                acc_flash_set('Brouillon mis à jour.');
            }
        } else {
            $newId = AccountingEntry::create($header, $rawLines);
            if ($status === 'validated') {
                AccountingEntry::setStatus($newId, 'validated');
                acc_flash_set("Pièce enregistrée et validée (#{$newId}).");
            } else {
                acc_flash_set("Brouillon enregistré (#{$newId}).");
            }
        }
    })) {
        header('Location: entries.php');
        exit;
    }
    // En cas d'erreur on recharge les lignes saisies
    $lines = array_map(fn($l) => [
        'account_id' => (int)$l['account_id'], 'third_party_id' => (int)$l['third_party_id'],
        'label' => $l['label'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'due_date' => $l['due_date'],
    ], $rawLines);
} else {
    $lines = $entry ? AccountingEntry::lines($editId) : [];
}

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_entry_form');
acc_flash();
?>

<form method="post" id="entry-form">
  <div class="card">
    <div class="acc-toolbar">
      <h2><?= $entry ? 'Modification de la pièce « ' . htmlspecialchars((string)$entry['entry_number']) . ' »' : 'Nouvelle pièce comptable' ?></h2>
      <a class="btn secondary small no-print" href="entries.php">Retour à la liste</a>
    </div>

    <div class="entry-head-grid">
      <div class="form-row">
        <label>Journal *</label>
        <select name="journal_id" required id="f-journal">
          <?php foreach ($journals as $j): ?>
          <option value="<?= $j['id'] ?>" <?= (int)$dfltJournalId === (int)$j['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($j['code'] . ' — ' . $j['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label>Exercice *</label>
        <select name="period_id" required id="f-period">
          <?php foreach ($periods as $p): ?>
          <option value="<?= $p['id'] ?>" data-start="<?= $p['start_date'] ?>" data-end="<?= $p['end_date'] ?>"
                  <?= (int)$dfltPeriodId === (int)$p['id'] ? 'selected' : '' ?> <?= $p['status'] !== 'open' ? 'disabled' : '' ?>>
            <?= htmlspecialchars($p['code']) ?><?= $p['status'] !== 'open' ? ' (clôturé)' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label>N° de pièce</label>
        <input name="entry_number" id="f-number" value="<?= htmlspecialchars($entry['entry_number'] ?? $dfltNumber) ?>"
               placeholder="auto" style="font-family:var(--font-mono);">
      </div>
      <div class="form-row">
        <label>Date de pièce *</label>
        <input type="date" name="entry_date" required id="f-date"
               value="<?= htmlspecialchars($entry['entry_date'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="form-row">
        <label>Référence (facture…)</label>
        <input name="reference" maxlength="60" value="<?= htmlspecialchars($entry['reference'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Tiers</label>
        <select name="third_party_id">
          <option value="">— Aucun —</option>
          <?php foreach ($tiers as $t): ?>
          <option value="<?= $t['id'] ?>" <?= (int)($entry['third_party_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($t['code'] . ' — ' . $t['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row" style="grid-column: span 2;">
        <label>Libellé de la pièce *</label>
        <input name="label" required maxlength="200" value="<?= htmlspecialchars($entry['label'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="card lines-editor">
    <h2 style="margin-top:0; font-size:15px;">Lignes d'écriture</h2>
    <table>
      <thead>
        <tr>
          <th style="width:30px;">#</th>
          <th style="width:26%;">Compte *</th>
          <th style="width:16%;">Tiers</th>
          <th>Libellé ligne</th>
          <th class="num" style="width:12%;">Débit</th>
          <th class="num" style="width:12%;">Crédit</th>
          <th style="width:11%;">Échéance</th>
          <th style="width:34px;"></th>
        </tr>
      </thead>
      <tbody id="lines-tbody">
        <?php foreach (($lines ?: []) as $l): ?>
        <tr class="line-row">
          <td class="mono line-no">1</td>
          <td class="account-cell">
            <select name="line_account_id[]" class="in-account" required>
              <option value="">— choisir —</option>
              <?php foreach ($accounts as $a): ?>
              <option value="<?= $a['id'] ?>" <?= (int)$l['account_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['number'] . ' — ' . $a['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" class="account-filter" placeholder="filtre rapide…">
          </td>
          <td>
            <select name="line_third_id[]" class="in-third">
              <option value="">—</option>
              <?php foreach ($tiers as $t): ?>
              <option value="<?= $t['id'] ?>" <?= (int)($l['third_party_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['code']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input name="line_label[]" class="in-label" value="<?= htmlspecialchars($l['label'] ?? '') ?>"></td>
          <td class="num-col"><input name="line_debit[]" class="in-debit" inputmode="decimal" value="<?= isset($l['debit']) && (float)$l['debit'] > 0 ? rtrim(rtrim(number_format((float)$l['debit'], 2, '.', ''), '0'), '.') : '' ?>"></td>
          <td class="num-col"><input name="line_credit[]" class="in-credit" inputmode="decimal" value="<?= isset($l['credit']) && (float)$l['credit'] > 0 ? rtrim(rtrim(number_format((float)$l['credit'], 2, '.', ''), '0'), '.') : '' ?>"></td>
          <td><input type="date" name="line_due[]" class="in-due" value="<?= htmlspecialchars($l['due_date'] ?? '') ?>"></td>
          <td><button type="button" class="line-remove" title="Supprimer la ligne">✕</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin-top:10px;"><button type="button" class="btn secondary small" id="add-line">＋ Ajouter une ligne</button></p>

    <template id="line-template">
      <tr class="line-row">
        <td class="mono line-no">1</td>
        <td class="account-cell">
          <select name="line_account_id[]" class="in-account" required>
            <option value="">— choisir —</option>
            <?php foreach ($accounts as $a): ?>
            <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['number'] . ' — ' . $a['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" class="account-filter" placeholder="filtre rapide…">
        </td>
        <td>
          <select name="line_third_id[]" class="in-third">
            <option value="">—</option>
            <?php foreach ($tiers as $t): ?>
            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['code']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input name="line_label[]" class="in-label"></td>
        <td class="num-col"><input name="line_debit[]" class="in-debit" inputmode="decimal"></td>
        <td class="num-col"><input name="line_credit[]" class="in-credit" inputmode="decimal"></td>
        <td><input type="date" name="line_due[]" class="in-due"></td>
        <td><button type="button" class="line-remove" title="Supprimer la ligne">✕</button></td>
      </tr>
    </template>

    <div class="balance-summary">
      <div class="balance-box"><div class="lbl">Total débits</div><div class="val amount-debit" id="sum-debit">0,00</div></div>
      <div class="balance-box"><div class="lbl">Total crédits</div><div class="val amount-credit" id="sum-credit">0,00</div></div>
      <div class="balance-box"><div class="lbl">Écart</div><div class="val" id="sum-diff">0,00</div></div>
      <div class="balance-box"><div class="lbl">Contrôle partie double</div><div class="val" id="balance-state">Saisissez les montants</div></div>
    </div>
  </div>

  <div class="no-print" style="display:flex; gap:10px; margin-bottom:30px;">
    <button class="btn secondary" type="submit" name="save_status" value="draft">Enregistrer le brouillon</button>
    <button class="btn" type="submit" name="save_status" value="validated">Enregistrer &amp; valider</button>
  </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
