<?php
require_once __DIR__ . '/helpers.php';
require_login();

$taskId = (int)($_GET['id'] ?? 0);
$task = fetch_task($taskId);
if (!$task) {
    redirect_with_message('tasks.php', 'Task not found.', 'error');
}

$photos = fetch_task_photos($taskId);              // 1..3 indexed usually
$photoCount = 0;
foreach ([1,2,3] as $i) { if (!empty($photos[$i])) $photoCount++; }

$pageTitle = 'Task #' . $taskId;
include __DIR__ . '/includes/header.php';
?>
<style>
/* Layout */
.card.card-compact { padding: 14px 16px; }
.card-compact .card-header { margin-bottom: 10px; }

/* Title row */
.task-title-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; flex-wrap: wrap;
}
.task-title-row h1 {
  margin: 0; font-size: 20px; font-weight: 700;
}

/* Details, compact two columns on desktop */
.details-simple {
  display: grid; gap: 8px; margin-top: 4px;
  grid-template-columns: repeat(2, minmax(220px, 1fr));
}
@media (max-width: 700px) { .details-simple { grid-template-columns: 1fr; } }
.details-simple p { margin: 0; font-size: 13px; color: #0f172a; }
.details-simple strong { color: #6b7280; font-weight: 600; }

/* Description box */
.desc-box {
  margin-top: 10px; padding: 10px 12px;
  border: 1px solid #eef1f6; border-radius: 8px; background: #fff;
}

/* Photo grid */
.photo-grid {
  display: grid; gap: 12px; margin-top: 8px;
  grid-template-columns: repeat(3, 1fr);
}
@media (max-width: 1000px) { .photo-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px)  { .photo-grid { grid-template-columns: 1fr; } }

.task-photo {
  width: 100%; height: 230px; object-fit: cover;
  border-radius: 12px; border: 1px solid #e2e8f0;
  background: #fff; cursor: zoom-in;
  transition: transform .12s ease, box-shadow .12s ease;
}
.task-photo:hover { transform: translateY(-2px); box-shadow: 0 12px 26px rgba(15, 23, 42, .12); }

/* Photo viewer */
.photo-viewer {
  position: fixed;
  inset: 0;
  display: none;
  align-items: center;
  justify-content: center;
  background: rgba(15, 23, 42, .88);
  z-index: 1400;
  padding: 1.5rem;
}
.photo-viewer[hidden] { display: none; }
.photo-viewer.is-open { display: flex; }
.photo-viewer__backdrop { position: absolute; inset: 0; }
.photo-viewer__panel {
  position: relative;
  width: min(92vw, 960px);
  background: #0f172a;
  border-radius: 18px;
  padding: 2.5rem 1.5rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  color: #fff;
  box-shadow: 0 45px 90px rgba(2,6,23,.5);
}
.photo-viewer__stage {
  width: 100%;
  min-height: 320px;
  border-radius: 12px;
  background: #0b1120;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.photo-viewer__stage img {
  max-width: 100%;
  max-height: 70vh;
  transition: transform .2s ease;
  transform-origin: center;
}
.photo-viewer__close {
  position: absolute;
  top: 14px;
  right: 14px;
  width: 40px;
  height: 40px;
  border-radius: 999px;
  background: rgba(15,23,42,.65);
  color: #fff;
  border: 0;
  font-size: 1.5rem;
  cursor: pointer;
}
.photo-viewer__nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 44px;
  height: 44px;
  border-radius: 999px;
  border: 0;
  background: rgba(15,23,42,.65);
  color: #fff;
  cursor: pointer;
  font-size: 1.4rem;
}
.photo-viewer__nav--prev { left: 10px; }
.photo-viewer__nav--next { right: 10px; }
.photo-viewer__controls {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
  color: #cbd5f5;
}
.photo-viewer__counter { font-weight: 600; }
.photo-viewer__zoom { display: flex; align-items: center; gap: .5rem; }
.photo-viewer__zoom button {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid rgba(255,255,255,.4);
  background: transparent;
  color: #fff;
  font-size: 1.1rem;
  cursor: pointer;
}
.photo-viewer__zoom input[type="range"] { width: 180px; }
</style>

<section class="card card-compact">
  <div class="card-header">
    <div class="task-title-row">
      <h1>
        <?php
          $titleBits = ['Task #' . (int)$taskId];
          if (!empty($task['title'])) $titleBits[] = sanitize($task['title']);
          echo implode(' — ', $titleBits);
        ?>
      </h1>
      <div class="actions" style="display:flex; gap:8px; flex-wrap:wrap;">
        <a class="btn" href="tasks.php">Back to list</a>
        <a class="btn" href="export_pdf.php?selected=<?php echo (int)$taskId; ?>" target="_blank">Export PDF</a>
        <a class="btn" href="export_room_pdf.php?room_id=<?php echo (int)$task['room_id']; ?>" target="_blank">Export Room PDF</a>
      </div>
    </div>
  </div>

  <!-- Details -->
  <div class="details-simple">
    <p><strong>Building:</strong> <?php echo sanitize($task['building_name']); ?></p>
    <p><strong>Room:</strong> <?php echo sanitize($task['room_number'] . ($task['room_label'] ? ' - ' . $task['room_label'] : '')); ?></p>
    <p><strong>Priority:</strong>
      <span class="badge <?php echo priority_class($task['priority']); ?>">
        <?php echo sanitize(priority_label($task['priority'])); ?>
      </span>
    </p>
    <p><strong>Status:</strong>
      <span class="badge <?php echo status_class($task['status']); ?>">
        <?php echo sanitize(status_label($task['status'])); ?>
      </span>
    </p>
    <p><strong>Assigned To:</strong> <?php echo sanitize($task['assigned_to'] ?? ''); ?></p>
    <p><strong>Due Date:</strong> <?php echo $task['due_date'] ? sanitize($task['due_date']) : '—'; ?></p>
    <p><strong>Created:</strong> <?php echo sanitize($task['created_at']); ?></p>
    <p><strong>Updated:</strong> <?php echo $task['updated_at'] ? sanitize($task['updated_at']) : '—'; ?></p>
  </div>

  <!-- Description -->
  <div class="desc-box">
    <strong style="color:#6b7280;">Description</strong>
    <div><?php echo nl2br(sanitize($task['description'] ?? 'No description.')); ?></div>
  </div>
</section>

<section class="card card-compact">
  <div class="card-header" style="margin-bottom:6px;">
    <h2 style="margin:0;">Photos <span class="muted small">(<?php echo (int)$photoCount; ?>/3)</span></h2>
  </div>

  <?php if ($photoCount > 0): ?>
    <div class="photo-grid" id="photoGrid">
      <?php $photoIndex = -1; foreach ([1,2,3] as $i): if (empty($photos[$i])) continue;
        $photoIndex++;
        $thumb = photo_public_url($photos[$i], 1200);
        $full  = photo_public_url($photos[$i], 2400);
      ?>
        <img
          class="task-photo"
          src="<?php echo sanitize($thumb); ?>"
          data-full="<?php echo sanitize($full); ?>"
          data-index="<?php echo (int)$photoIndex; ?>"
          tabindex="0"
          role="button"
          loading="lazy"
          alt="Task photo <?php echo (int)$i; ?>">
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="muted">No photos uploaded.</p>
  <?php endif; ?>
</section>

<!-- Photo viewer -->
<div class="photo-viewer" id="photoViewer" hidden aria-hidden="true">
  <div class="photo-viewer__backdrop" data-viewer-close></div>
  <div class="photo-viewer__panel" role="dialog" aria-modal="true" aria-label="Task photo viewer">
    <button class="photo-viewer__close" type="button" data-viewer-close aria-label="Close">&times;</button>
    <button class="photo-viewer__nav photo-viewer__nav--prev" type="button" data-viewer-prev aria-label="Previous photo">&#10094;</button>
    <button class="photo-viewer__nav photo-viewer__nav--next" type="button" data-viewer-next aria-label="Next photo">&#10095;</button>
    <div class="photo-viewer__stage">
      <img id="photoViewerImg" alt="" src="">
    </div>
    <div class="photo-viewer__controls">
      <span class="photo-viewer__counter" id="photoViewerCounter">1 / 1</span>
      <div class="photo-viewer__zoom">
        <button type="button" data-zoom-out aria-label="Zoom out">&minus;</button>
        <input type="range" id="photoViewerZoom" min="100" max="250" step="10" value="100" aria-label="Zoom">
        <button type="button" data-zoom-in aria-label="Zoom in">+</button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const thumbnails = Array.from(document.querySelectorAll('.task-photo'));
  const viewer = document.getElementById('photoViewer');
  const viewerImg = document.getElementById('photoViewerImg');
  const counter = document.getElementById('photoViewerCounter');
  const zoomSlider = document.getElementById('photoViewerZoom');
  const zoomInBtn = viewer ? viewer.querySelector('[data-zoom-in]') : null;
  const zoomOutBtn = viewer ? viewer.querySelector('[data-zoom-out]') : null;
  const prevBtn = viewer ? viewer.querySelector('[data-viewer-prev]') : null;
  const nextBtn = viewer ? viewer.querySelector('[data-viewer-next]') : null;
  const closeTargets = viewer ? viewer.querySelectorAll('[data-viewer-close], .photo-viewer__backdrop') : [];
  if (!viewer || !thumbnails.length) return;

  let currentIndex = 0;
  let zoomLevel = 100;

  if (thumbnails.length < 2) {
    if (prevBtn) prevBtn.style.display = 'none';
    if (nextBtn) nextBtn.style.display = 'none';
  }

  const setZoom = (value) => {
    zoomLevel = Math.max(100, Math.min(250, Number(value) || 100));
    if (zoomSlider) {
      zoomSlider.value = String(zoomLevel);
    }
    if (viewerImg) {
      viewerImg.style.transform = `scale(${zoomLevel / 100})`;
    }
  };

  const updateCounter = () => {
    if (counter) {
      counter.textContent = `${currentIndex + 1} / ${thumbnails.length}`;
    }
  };

  const loadPhoto = (index) => {
    const thumb = thumbnails[index];
    if (!thumb) return;
    const full = thumb.dataset.full || thumb.src;
    if (viewerImg) {
      viewerImg.src = full;
      viewerImg.alt = thumb.alt || 'Task photo';
    }
    updateCounter();
    setZoom(zoomLevel);
  };

  const openViewer = (index) => {
    currentIndex = Math.max(0, Math.min(index, thumbnails.length - 1));
    zoomLevel = 100;
    loadPhoto(currentIndex);
    viewer.hidden = false;
    viewer.classList.add('is-open');
    viewer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('viewer-open');
  };

  const closeViewer = () => {
    viewer.classList.remove('is-open');
    viewer.setAttribute('aria-hidden', 'true');
    viewer.hidden = true;
    if (viewerImg) {
      viewerImg.removeAttribute('src');
    }
    document.body.classList.remove('viewer-open');
  };

  const showOffset = (delta) => {
    if (!thumbnails.length) return;
    currentIndex = (currentIndex + delta + thumbnails.length) % thumbnails.length;
    loadPhoto(currentIndex);
  };

  thumbnails.forEach((thumb, index) => {
    thumb.addEventListener('click', () => openViewer(index));
    thumb.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openViewer(index);
      }
    });
  });

  closeTargets.forEach((el) => {
    el.addEventListener('click', (event) => {
      if (el === event.target) {
        closeViewer();
      }
    });
  });

  if (prevBtn) prevBtn.addEventListener('click', () => showOffset(-1));
  if (nextBtn) nextBtn.addEventListener('click', () => showOffset(1));

  if (zoomSlider) {
    zoomSlider.addEventListener('input', (event) => setZoom(event.target.value));
  }
  if (zoomInBtn) {
    zoomInBtn.addEventListener('click', () => setZoom(zoomLevel + 10));
  }
  if (zoomOutBtn) {
    zoomOutBtn.addEventListener('click', () => setZoom(zoomLevel - 10));
  }

  document.addEventListener('keydown', (event) => {
    if (viewer.hasAttribute('hidden')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeViewer();
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      showOffset(1);
    } else if (event.key === 'ArrowLeft') {
      event.preventDefault();
      showOffset(-1);
    } else if (event.key === '+' || event.key === '=') {
      event.preventDefault();
      setZoom(zoomLevel + 10);
    } else if (event.key === '-' || event.key === '_') {
      event.preventDefault();
      setZoom(zoomLevel - 10);
    }
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
