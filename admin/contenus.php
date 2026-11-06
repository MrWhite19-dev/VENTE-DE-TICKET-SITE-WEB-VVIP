<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();
require_once __DIR__ . '/_layout.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    if ($action === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM contenus WHERE id = ?')->execute([$id]);
        admin_flash('success', 'Le contenu a été supprimé.');
        redirect('contenus.php');
    }

    if ($action === 'save') {
        $title = trim((string) ($_POST['titre'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $type = (string) ($_POST['type_contenu'] ?? 'bande_annonce');
        $status = (string) ($_POST['statut'] ?? 'brouillon');
        $media = trim((string) ($_POST['fichier_media'] ?? ''));
        if (
            mb_strlen($title) < 2 || mb_strlen($title) > 200 ||
            mb_strlen($description) > 10000 || mb_strlen($media) > 1000 ||
            !in_array($type, ['bande_annonce', 'actualite', 'media'], true) ||
            !in_array($status, ['brouillon', 'publie'], true) ||
            !valid_video_source($media)
        ) {
            $error = 'Vérifiez le titre, le type, le statut ou la source média.';
        } else {
            try {
                $currentMedia = ['fichier_media' => null, 'miniature' => null];
                if ($id > 0) {
                    $current = $pdo->prepare('SELECT fichier_media, miniature FROM contenus WHERE id = ?');
                    $current->execute([$id]);
                    $currentMedia = $current->fetch() ?: $currentMedia;
                }
                $video = upload_media('video_file', 'videos', ['video/mp4'], 100 * 1024 * 1024);
                $image = upload_media('miniature_file', 'affiches', ['image/jpeg', 'image/png', 'image/webp'], 5 * 1024 * 1024);
                if ($video !== null) {
                    $media = $video;
                } elseif (isset($_POST['remove_media'])) {
                    $media = '';
                } elseif ($media === '') {
                    $media = $currentMedia['fichier_media'] ?? '';
                }
                $thumbnail = $image ?? (isset($_POST['remove_thumbnail']) ? null : $currentMedia['miniature']);
                $publishedAt = $status === 'publie' ? date('Y-m-d H:i:s') : null;
                if ($id > 0) {
                    $statement = $pdo->prepare(
                        'UPDATE contenus SET titre=?, description=?, type_contenu=?, fichier_media=?, miniature=?, statut=?,
                         date_publication=IF(? = \'publie\', COALESCE(date_publication, ?), NULL) WHERE id=?'
                    );
                    $statement->execute([$title, $description, $type, $media !== '' ? $media : null, $thumbnail, $status, $status, $publishedAt, $id]);
                    admin_flash('success', 'Le contenu a été mis à jour.');
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO contenus (titre, description, type_contenu, fichier_media, miniature, statut, date_publication)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $statement->execute([$title, $description, $type, $media !== '' ? $media : null, $thumbnail, $status, $publishedAt]);
                    admin_flash('success', 'Le contenu a été créé.');
                }
                redirect('contenus.php');
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            } catch (PDOException $exception) {
                error_log('Content save failed: ' . $exception->getMessage());
                $error = 'Impossible d’enregistrer le contenu.';
            }
        }
    }
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editing = null;
if ($editId) {
    $query = $pdo->prepare('SELECT * FROM contenus WHERE id = ?');
    $query->execute([$editId]);
    $editing = $query->fetch() ?: null;
}
$contents = $pdo->query('SELECT * FROM contenus ORDER BY date_creation DESC')->fetchAll();
$previewEmbed = $editing && !empty($editing['fichier_media']) ? video_embed_url($editing['fichier_media']) : null;

admin_header('Bandes-annonces & contenus', 'contenus');
?>
<section class="admin-panel">
  <h2><?= $editing ? 'Modifier le contenu' : 'Ajouter une bande-annonce / un contenu' ?></h2>
  <?php if ($error !== ''): ?><div class="admin-flash error"><?= h($error) ?></div><?php endif; ?>
  <form class="admin-form" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
    <label>Titre<input name="titre" maxlength="200" required value="<?= h($editing['titre'] ?? '') ?>"></label>
    <label>Type<select name="type_contenu"><?php foreach (['bande_annonce' => 'Bande-annonce', 'actualite' => 'Actualité', 'media' => 'Média'] as $value => $label): ?><option value="<?= $value ?>" <?= ($editing['type_contenu'] ?? 'bande_annonce') === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
    <label class="full">Description<textarea name="description" maxlength="10000"><?= h($editing['description'] ?? '') ?></textarea></label>
    <label class="full">Lien HTTPS YouTube/Vimeo ou chemin d’un MP4 existant<input name="fichier_media" maxlength="1000" value="<?= h($editing['fichier_media'] ?? '') ?>" placeholder="https://youtu.be/..."></label>
    <label>Vidéo MP4 (100 Mo max)<input name="video_file" type="file" accept="video/mp4"></label>
    <label>Miniature (JPEG, PNG, WebP · 5 Mo max)<input name="miniature_file" type="file" accept="image/jpeg,image/png,image/webp"></label>
    <?php if ($editing && $editing['fichier_media']): ?><label class="full"><span><input type="checkbox" name="remove_media" value="1"> Retirer le média actuel</span></label><?php endif; ?>
    <?php if ($editing && $editing['miniature']): ?><label class="full"><span><input type="checkbox" name="remove_thumbnail" value="1"> Retirer la miniature actuelle</span></label><?php endif; ?>
    <label>Publication<select name="statut"><option value="brouillon" <?= ($editing['statut'] ?? '') === 'brouillon' ? 'selected' : '' ?>>Brouillon</option><option value="publie" <?= ($editing['statut'] ?? '') === 'publie' ? 'selected' : '' ?>>Publié</option></select></label>
    <div class="full admin-actions"><button class="admin-button" type="submit"><?= $editing ? 'Enregistrer' : 'Créer le contenu' ?></button><?php if ($editing): ?><a href="contenus.php" class="admin-button secondary">Annuler</a><?php endif; ?></div>
  </form>
  <?php if ($editing && !empty($editing['fichier_media'])): ?>
    <div class="admin-note">Prévisualisation du média enregistré — pour une nouvelle vidéo, enregistrez d’abord en brouillon.</div>
    <?php if ($previewEmbed): ?><iframe src="<?= h($previewEmbed) ?>" title="Prévisualisation de <?= h($editing['titre']) ?>" allow="fullscreen; picture-in-picture" allowfullscreen style="display:block;width:min(100%,720px);aspect-ratio:16/9;border:0;margin-top:12px"></iframe>
    <?php elseif (valid_video_source($editing['fichier_media'])): ?><video controls preload="metadata" style="display:block;width:min(100%,720px);margin-top:12px"><source src="../<?= h($editing['fichier_media']) ?>" type="video/mp4"></video><?php endif; ?>
  <?php endif; ?>
</section>
<section class="admin-panel">
  <h2>Contenus enregistrés</h2>
  <table class="admin-table"><thead><tr><th>Titre / description</th><th>Type</th><th>Statut</th><th>Prévisualisation</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($contents as $content): ?>
    <tr><td class="wrap"><?= h($content['titre']) ?><br><span class="muted"><?= h($content['description'] ?? '') ?></span></td><td><?= h($content['type_contenu']) ?></td><td><?= h($content['statut']) ?></td>
      <td><?php if ($content['miniature']): ?><img src="../<?= h($content['miniature']) ?>" alt="" width="90"><?php elseif ($content['fichier_media']): ?><a href="<?= h(preg_match('~^https://~i', $content['fichier_media']) ? $content['fichier_media'] : '../' . $content['fichier_media']) ?>" target="_blank" rel="noopener">Ouvrir ↗</a><?php else: ?>—<?php endif; ?></td>
      <td><div class="admin-actions"><a class="admin-button secondary" href="contenus.php?edit=<?= (int) $content['id'] ?>">Modifier</a>
        <form method="post" onsubmit="return confirm('Supprimer ce contenu ?')"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $content['id'] ?>"><button class="admin-button danger" type="submit">Supprimer</button></form></div></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$contents): ?><tr><td colspan="5">Aucun contenu ajouté.</td></tr><?php endif; ?>
  </tbody></table>
</section>
<?php admin_footer(); ?>
