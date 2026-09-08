<?php
require_once __DIR__ . '/_init.php';

$pageTitle = 'Comptabilité — Grand livre';
$activeNav = 'acc_ledger';

// ---- Lettrage manuel de lignes sélectionnées ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'letter' && acc_handle(function () {
    $ids = array_map('intval', $_POST['line_ids'] ?? []);
    if (count($ids) < 2) {
        throw new InvalidArgumentException('Sélectionnez au moins deux lignes à lettrer.');
    }
    $pdo = Database::getConnection();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT l.id, l.debit, l.credit, l.lettering,
                e.status, e.period_id
         FROM acc_entry_lines l JOIN acc_entries e ON e.id = l.entry_id
         WHERE l.id IN ({$in})"
    );
    $stmt->execute($ids);
    $lines = $stmt->fetchAll();

    $debit = 0.0; $credit = 0.0;
    foreach ($lines as $l) {
        if ($l['status'] !== 'validated') {
            throw new DomainException('Le lettrage ne concerne que des lignes d\'écritures validées.');
        }
        if ($l['lettering']) {
            throw new DomainException("La ligne #{$l['id']} est déjà lettrée ({$l['lettering']}).");
        }
        $debit += (float)$l['debit'];
        $credit += (float)$l['credit'];
    }
    if (abs($debit - $credit) > 0.005) {
        throw new DomainException(sprintf(
            'Lettrage refusé : la sélection n\'est pas équilibrée (débits %.2f / crédits %.2f).',
            $debit, $credit
        ));
    }
    $code = 'L' . strtoupper(base_convert((string)time(), 10, 36));
    $upd = $pdo->prepare(
        "UPDATE acc_entry_lines SET lettering = :code, lettered_at = CURRENT_DATE
         WHERE id IN ({$in})"
    );
    $upd->execute(['code' => $code] + $ids);
    acc_flash_set("Lignes lettrées avec le code {$code}.");
})) { header('Location: ledger.php?' . http_build_query($_GET)); exit; }

// Délettrage d'une ligne
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unletter' && acc_handle(function () use ($pdo) {
    $pdo->prepare('UPDATE acc_entry_lines SET lettering = NULL, lettered_at = NULL WHERE id = :id')
        ->execute(['id' => (int)$_POST['line_id']]);
    acc_flash_set('Lettrage supprimé.');
})) { header('Location: ledger.php?' . http_build_query($_GET)); exit; }

// ---- Filtres ----
$accountsList = AccountingReport::ledgerAccounts();
$accountId = (int)($_GET['account_id'] ?? 0);
$fFrom = trim($_GET['date_from'] ?? '');
$fTo   = trim($_GET['date_to'] ?? '');

$data = null;
if ($accountId) {
    try {
        $data = AccountingReport::ledger($accountId, $fFrom ?: null, $fTo ?: null);
    } catch (\Throwable $e) {
        acc_flash_set('', $e->getMessage());
    }
}
$qParams = array_filter(['account_id' => $accountId, 'date_from' => $fFrom, 'date_to' => $fTo]);

require_once __DIR__ . '/../includes/header.php';
acc_tabs('acc_ledger');
acc_flash();
?>

<div class="card no-print">
  <form method="get" class="form-inline">
    <div class="form-row" style="min-width:260px;">
      <label>Compte *</label>
      <select name="account_id" required>
        <option value="">— Choisir un compte —</option>
        <?php foreach ($accountsList as $a): ?>
        <option value="<?= $a['id'] ?>" <?= $accountId === (int)$a['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($a['number'] . ' — ' . $a['label']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>Du</label><input type="date" name="date_from" value="<?= htmlspecialchars($fFrom) ?>"></div>
    <div class="form-row"><label>Au</label><input type="date" name="date_to" value="<?= htmlspecialchars($fTo) ?>"></div>
    <button class="btn">Afficher</button>
    <?php if ($accountId): ?>
    <a class="btn secondary" href="ledger.php?account_id=<?= $accountId ?>">Tout l'historique</a>
    <?php endif; ?>
  </form>
</div>

<?php if (!$data): ?>
<div class="card"><p class="empty">Sélectionnez un compte pour consulter son grand livre.</p></div>
<?php else: ?>
<div class="card">
  <div class="acc-toolbar">
    <h2 class="mono"><?= htmlspecialchars($data['account']['number']) ?> — <?= htmlspecialchars($data['account']['label']) ?></h2>
    <div class="acc-actions no-print">
      <a class="btn secondary small" href="export.php?type=ledger&<?= http_build_query($qParams) ?>">Export CSV</a>
      <a class="btn secondary small" target="_blank" href="print.php?type=ledger&<?= http_build_query($qParams) ?>">Imprimer</a>
    </div>
  </div>

  <form method="post">
    <?php foreach ($qParams as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars((string)$v) ?>"><?php endforeach; ?>

    <table class="datatable" data-per-page="20">
      <thead>
        <tr>
          <th data-type="date">Date</th><th>Pièce</th><th>Jl</th><th>Libellé</th><th>Tiers</th><th>Lett.</th>
          <th class="num">Débit</th><th class="num">Crédit</th><th class="num">Solde progressif</th>
          <th class="no-print" style="width:30px;" title="Cocher pour lettrer">✉</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($data['opening'] != 0.0): ?>
        <tr class="section-row">
          <td colspan="6">Solde à nouveau <?= $fFrom ? 'au ' . Accounting::dateFr($fFrom) : '(cumul)' ?></td>
          <td class="num"></td><td class="num"></td>
          <td class="num"><?= Accounting::signedBalance($data['opening']) ?></td>
          <td class="no-print"></td>
        </tr>
        <?php endif; ?>
        <?php foreach ($data['rows'] as $r): ?>
        <tr>
          <td><?= Accounting::dateFr($r['entry_date']) ?></td>
          <td class="mono"><?= htmlspecialchars((string)$r['entry_number']) ?></td>
          <td><span class="pill jtype"><?= htmlspecialchars($r['journal_code']) ?></span></td>
          <td><?= htmlspecialchars(mb_substr((string)($r['line_label'] ?: $r['entry_label']), 0, 45)) ?></td>
          <td><?= htmlspecialchars($r['third_name'] ?? '') ?></td>
          <td class="mono"><?= htmlspecialchars((string)$r['lettering']) ?></td>
          <td class="num amount-debit"><?= (float)$r['debit'] > 0 ? Accounting::money($r['debit']) : '' ?></td>
          <td class="num amount-credit"><?= (float)$r['credit'] > 0 ? Accounting::money($r['credit']) : '' ?></td>
          <td class="num"><?= Accounting::signedBalance((float)$r['running']) ?></td>
          <td class="no-print" style="text-align:center;">
            <?php if ($r['lettering']): ?>
              <button type="submit" name="action" value="unletter" class="line-remove"
                      title="Délettrer" formaction="ledger.php?<?= http_build_query($qParams) ?>"
                      onclick="this.form.line_id.value=<?= (int)$r['line_id'] ?>; return confirm('Supprimer le lettrage de cette ligne ?');">
                ↺
              </button>
              <input type="hidden" name="line_id" value="">
            <?php else: ?>
              <input type="checkbox" name="line_ids[]" value="<?= (int)$r['line_id'] ?>">
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6">Totaux</td>
          <td class="num"><?= Accounting::money($data['total_debit']) ?></td>
          <td class="num"><?= Accounting::money($data['total_credit']) ?></td>
          <td class="num"><?= Accounting::signedBalance($data['closing']) ?></td>
          <td class="no-print"></td>
        </tr>
      </tfoot>
    </table>

    <div class="no-print" style="margin-top:12px;">
      <button class="btn small" type="submit" name="action" value="letter"
              onclick="return confirm('Lettrer les lignes cochées ? La somme débits − crédits doit être nulle.');">
        ✉ Lettrer la sélection
      </button>
      <span style="color:var(--ink-soft); font-size:12.5px;">
        Cochez des lignes débit/crédit qui s'équilibrent pour les lettrer (rapprochement).
      </span>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
