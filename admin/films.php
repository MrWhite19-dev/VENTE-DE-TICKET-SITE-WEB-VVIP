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
        $count = $pdo->prepare('SELECT COUNT(*) FROM seances WHERE film_id = ?');
        $count->execute([$id]);
        if ((int) $count->fetchColumn() > 0) {
            $pdo->prepare("UPDATE films SET statut = 'archive' WHERE id = ?")->execute([$id]);
            admin_flash('success', 'Le film associé à des séances a été archivé.');
        } else {
            $pdo->prepare('DELETE FROM films WHERE id = ?')->execute([$id]);
            admin_flash('success', 'Le film a été supprimé.');
        }
        redirect('films.php');
    }

    if ($action === 'save') {
        $title = trim((string) ($_POST['titre'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $status = (string) ($_POST['statut'] ?? 'brouillon');
        $video = trim((string) ($_POST['video_bande_annonce'] ?? ''));
        $category = trim((string) ($_POST['categorie'] ?? ''));
        $director = trim((string) ($_POST['realisateur'] ?? ''));
        $actors = trim((string) ($_POST['acteurs'] ?? ''));
        if ($slug === '') {
            $transliterated = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) : $title;
            $slug = strtolower((string) $transliterated);
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        }
        $durationInput = trim((string) ($_POST['duree'] ?? ''));
        $duration = $durationInput === '' ? null : filter_var($durationInput, FILTER_VALIDATE_INT);
        $releaseDate = trim((string) ($_POST['date_sortie'] ?? ''));
        $releaseDateObject = $releaseDate === '' ? null : DateTime::createFromFormat('!Y-m-d', $releaseDate);
        $validReleaseDate = $releaseDate === '' || (
            $releaseDateObject !== false && $releaseDateObject->format('Y-m-d') === $releaseDate
        );
        if (
            mb_strlen($title) < 2 || mb_strlen($title) > 200 ||
            mb_strlen($slug) < 2 || mb_strlen($slug) > 220 ||
            !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) ||
            mb_strlen($description) < 1 || mb_strlen($description) > 10000 ||
            mb_strlen($video) > 1000 || mb_strlen($category) > 120 ||
            mb_strlen($director) > 190 || mb_strlen($actors) > 5000 ||
            !in_array($status, ['brouillon', 'publie', 'archive'], true) ||
            ($durationInput !== '' && ($duration === false || $duration < 1 || $duration > 1000)) ||
            !$validReleaseDate ||
            !valid_video_source($video)
        ) {
            $error = 'Vérifiez le titre, le slug, le statut ou le lien vidéo.';
        } else {
            try {
                $currentMedia = ['image_affiche' => null, 'image_banniere' => null];
                if ($id > 0) {
                    $currentQuery = $pdo->prepare('SELECT image_affiche, image_banniere FROM films WHERE id = ?');
                    $currentQuery->execute([$id]);
                    $currentMedia = $currentQuery->fetch() ?: $currentMedia;
                }
                $poster = upload_media('image_affiche', 'affiches', ['image/jpeg', 'image/png', 'image/webp'], 5 * 1024 * 1024);
                $banner = upload_media('image_banniere', 'bannieres', ['image/jpeg', 'image/png', 'image/webp'], 8 * 1024 * 1024);
                $uploadedVideo = upload_media('video_file', 'videos', ['video/mp4'], 100 * 1024 * 1024);
                if ($uploadedVideo !== null) {
                    $video = $uploadedVideo;
                }

                $values = [
                    $title, $slug, $description,
                    isset($_POST['remove_poster']) ? null : $currentMedia['image_affiche'],
                    isset($_POST['remove_banner']) ? null : $currentMedia['image_banniere'],
                    $video !== '' ? $video : null,
                    $duration !== false ? $duration : null,
                    $category ?: null,
                    $director ?: null,
                    $actors ?: null,
                    $releaseDate !== '' ? $releaseDate : null,
                    $status,
                ];
                if ($poster !== null) {
                    $values[3] = $poster;
                }
                if ($banner !== null) {
                    $values[4] = $banner;
                }

                if ($id > 0) {
                    $statement = $pdo->prepare(
                        'UPDATE films SET titre=?, slug=?, description=?, image_affiche=?, image_banniere=?,
                         video_bande_annonce=?, duree=?, categorie=?, realisateur=?, acteurs=?, date_sortie=?, statut=?
                         WHERE id=?'
                    );
                    $statement->execute([...$values, $id]);
                    admin_flash('success', 'Les informations du film ont été mises à jour.');
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO films (titre, slug, description, image_affiche, image_banniere, video_bande_annonce,
                         duree, categorie, realisateur, acteurs, date_sortie, statut) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    $statement->execute($values);
                    admin_flash('success', 'Le film a été créé.');
                }
                redirect('films.php');
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            } catch (PDOException $exception) {
                error_log('Film save failed: ' . $exception->getMessage());
                $error = 'Enregistrement impossible. Vérifiez que le slug n’est pas déjà utilisé.';
            }
        }
    }
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editing = null;
if ($editId) {
    $statement = $pdo->prepare('SELECT * FROM films WHERE id = ?');
    $statement->execute([$editId]);
    $editing = $statement->fetch() ?: null;
}
$films = $pdo->query(
    'SELECT f.*, (SELECT COUNT(*) FROM seances s WHERE s.film_id=f.id) AS seances_count
     FROM films f ORDER BY f.date_modification DESC'
)->fetchAll();
$previewEmbed = $editing && !empty($editing['video_bande_annonce'])
    ? video_embed_url($editing['video_bande_annonce'])
    : null;

admin_header('Gestion des films', 'films');
?>
<section class="admin-panel">
  <h2><?= $editing ? 'Modifier le film' : 'Ajouter un film' ?></h2>
  <?php if ($error !== ''): ?><div class="admin-flash error"><?= h($error) ?></div><?php endif; ?>
  <form class="admin-form" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
    <label>Titre<input name="titre" maxlength="200" required value="<?= h($editing['titre'] ?? '') ?>"></label>
    <label>Slug<input name="slug" maxlength="220" value="<?= h($editing['slug'] ?? '') ?>" placeholder="Généré depuis le titre"></label>
    <label class="full">Description<textarea name="description" maxlength="10000" required><?= h($editing['description'] ?? '') ?></textarea></label>
    <label>Affiche (JPEG, PNG, WebP · 5 Mo max)<input name="image_affiche" type="file" accept="image/jpeg,image/png,image/webp"></label>
    <label>Bannière (JPEG, PNG, WebP · 8 Mo max)<input name="image_banniere" type="file" accept="image/jpeg,image/png,image/webp"></label>
    <?php if ($editing && $editing['image_affiche']): ?><label><span><input type="checkbox" name="remove_poster" value="1"> Retirer l’affiche actuelle</span></label><?php endif; ?>
    <?php if ($editing && $editing['image_banniere']): ?><label><span><input type="checkbox" name="remove_banner" value="1"> Retirer la bannière actuelle</span></label><?php endif; ?>
    <label class="full">Bande-annonce (fichier MP4 ou lien HTTPS YouTube/Vimeo)
      <input name="video_bande_annonce" maxlength="1000" value="<?= h($editing['video_bande_annonce'] ?? '') ?>" placeholder="https://youtu.be/...">
      <input name="video_file" type="file" accept="video/mp4">
    </label>
    <label>Durée (minutes)<input name="duree" type="number" min="1" max="1000" value="<?= h((string) ($editing['duree'] ?? '')) ?>"></label>
    <label>Catégorie<input name="categorie" maxlength="120" value="<?= h($editing['categorie'] ?? '') ?>"></label>
    <label>Réalisateur<input name="realisateur" maxlength="190" value="<?= h($editing['realisateur'] ?? '') ?>"></label>
    <label>Acteurs<input name="acteurs" maxlength="5000" value="<?= h($editing['acteurs'] ?? '') ?>"></label>
    <label>Date de sortie<input name="date_sortie" type="date" value="<?= h($editing['date_sortie'] ?? '') ?>"></label>
    <label>Publication<select name="statut"><?php foreach (['brouillon', 'publie', 'archive'] as $status): ?><option value="<?= $status ?>" <?= ($editing['statut'] ?? 'brouillon') === $status ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
    <div class="full admin-actions"><button class="admin-button" type="submit"><?= $editing ? 'Enregistrer les modifications' : 'Créer le film' ?></button><?php if ($editing): ?><a class="admin-button secondary" href="films.php">Annuler</a><?php endif; ?></div>
  </form>
  <?php if ($editing && !empty($editing['video_bande_annonce'])): ?>
    <div class="admin-note">Prévisualisation de la bande-annonce enregistrée.</div>
    <?php if ($previewEmbed): ?><iframe src="<?= h($previewEmbed) ?>" title="Prévisualisation de <?= h($editing['titre']) ?>" allow="fullscreen; picture-in-picture" allowfullscreen style="display:block;width:min(100%,720px);aspect-ratio:16/9;border:0;margin-top:12px"></iframe>
    <?php elseif (valid_video_source($editing['video_bande_annonce'])): ?><video controls preload="metadata" style="display:block;width:min(100%,720px);margin-top:12px"><source src="../<?= h($editing['video_bande_annonce']) ?>" type="video/mp4"></video><?php endif; ?>
  <?php endif; ?>
</section>
<section class="admin-panel">
  <h2>Films enregistrés</h2>
  <table class="admin-table"><thead><tr><th>Titre</th><th>Catégorie</th><th>Séances</th><th>Statut</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($films as $film): ?><tr>
    <td class="wrap"><?= h($film['titre']) ?></td><td><?= h($film['categorie'] ?? '—') ?></td><td><?= (int) $film['seances_count'] ?></td><td><?= h($film['statut']) ?></td>
    <td><div class="admin-actions"><a class="admin-button secondary" href="films.php?edit=<?= (int) $film['id'] ?>">Modifier</a>
      <form method="post" onsubmit="return confirm('Supprimer ou archiver ce film ?')"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $film['id'] ?>"><button class="admin-button danger" type="submit">Supprimer</button></form>
    </div></td>
  </tr><?php endforeach; ?>
  </tbody></table>
</section>
<?php admin_footer(); ?>
