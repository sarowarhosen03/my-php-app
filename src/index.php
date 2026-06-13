<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/tmdb.php';

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;
$genre = sanitize($_GET['genre'] ?? '');
$type = sanitize($_GET['type'] ?? '');
$search = sanitize($_GET['q'] ?? '');

// Build query
$where = ["is_published = 1"];
$params = [];

if ($search) {
    $where[] = "MATCH(title, original_title, overview) AGAINST(? IN BOOLEAN MODE)";
    $params[] = $search . '*';
}
if ($genre) {
    $where[] = "genres LIKE ?";
    $params[] = "%$genre%";
}
if ($type) {
    $where[] = "content_type = ?";
    $params[] = $type;
}

$whereSQL = "WHERE " . implode(" AND ", $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM movies $whereSQL");
$totalStmt->execute($params);
$total = $totalStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

$stmt = $db->prepare("SELECT * FROM movies $whereSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$movies = $stmt->fetchAll();

// Featured movies for hero
$featuredStmt = $db->prepare("SELECT * FROM movies WHERE is_featured = 1 AND is_published = 1 AND backdrop_path IS NOT NULL ORDER BY created_at DESC LIMIT 5");
$featuredStmt->execute();
$featured = $featuredStmt->fetchAll();

// Trending (most viewed)
$trendingStmt = $db->prepare("SELECT * FROM movies WHERE is_published = 1 ORDER BY views DESC LIMIT 8");
$trendingStmt->execute();
$trending = $trendingStmt->fetchAll();

// All genres
$genreStmt = $db->prepare("SELECT DISTINCT genres FROM movies WHERE is_published = 1 AND genres != ''");
$genreStmt->execute();
$allGenres = [];
foreach ($genreStmt->fetchAll() as $row) {
    foreach (explode(',', $row['genres']) as $g) {
        $g = trim($g);
        if ($g && !in_array($g, $allGenres)) $allGenres[] = $g;
    }
}
sort($allGenres);

// Dynamic settings
$siteName    = getSiteName();
$tagline     = getSiteTagline();
$siteUrl     = SITE_URL;

// SEO settings
$seoMetaDesc     = getSetting('seo_meta_description', "$siteName - $tagline. Download movies, web series & anime for free.");
$seoKeywords     = getSetting('seo_keywords', '');
$googleVerify    = getSetting('seo_google_verify', '');
$seoSchema       = getSetting('seo_schema_enable', '1');
$canonicalBase   = getSetting('seo_canonical_base', $siteUrl);
$adsHeader       = getSetting('ads_header', '');
$adsSidebar      = getSetting('ads_sidebar', '');

$pageTitle = ($search ? "Search: $search – " : '') . "$siteName – $tagline";
$canonicalUrl = rtrim($canonicalBase, '/') . '/index.php' . ($search || $genre || $type ? '?' . http_build_query(array_filter(['q'=>$search,'genre'=>$genre,'type'=>$type])) : '');

// JSON-LD WebSite schema
$schemaJson = '';
if ($seoSchema === '1' && !$search) {
    $schemaData = [
        "@context" => "https://schema.org",
        "@type"    => "WebSite",
        "name"     => $siteName,
        "url"      => rtrim($canonicalBase, '/') . '/',
        "description" => $seoMetaDesc,
        "potentialAction" => [
            "@type"       => "SearchAction",
            "target"      => rtrim($canonicalBase, '/') . "/index.php?q={search_term_string}",
            "query-input" => "required name=search_term_string"
        ]
    ];
    $schemaJson = json_encode($schemaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($seoMetaDesc) ?>">
<?php if ($seoKeywords): ?><meta name="keywords" content="<?= htmlspecialchars($seoKeywords) ?>"><?php endif; ?>
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
<?php if ($googleVerify): ?><meta name="google-site-verification" content="<?= htmlspecialchars($googleVerify) ?>"><?php endif; ?>
<meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($seoMetaDesc) ?>">
<meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
<?php if ($schemaJson): ?>
<script type="application/ld+json"><?= $schemaJson ?></script>
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700;900&family=Oswald:wght@400;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="public/css/style.css">
<?php if ($adsHeader): ?><?= $adsHeader ?><?php endif; ?>
</head>
<body>

<!-- HEADER -->
<header class="site-header">
  <nav class="navbar" role="navigation" aria-label="Main navigation">
    <a href="index.php" class="nav-logo" aria-label="<?= htmlspecialchars($siteName) ?> Home">
      <span class="logo-text"><?= htmlspecialchars($siteName) ?></span>
    </a>

    <div class="nav-search">
      <input type="search" id="navSearch" placeholder="Search movies, series, anime..."
             aria-label="Search" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
      <button class="search-btn" id="searchSubmit" aria-label="Search"><i class="fas fa-search"></i></button>
      <div class="search-dropdown" id="searchDropdown" role="listbox" aria-label="Search suggestions"></div>
    </div>

    <ul class="nav-menu" id="navMenu" role="list">
      <button class="nav-close-btn" id="navCloseBtn" aria-label="Close menu"><i class="fas fa-times"></i></button>
      <li><a href="index.php" class="<?= !$type ? 'active' : '' ?>"><i class="fas fa-home"></i> Home</a></li>
      <li><a href="index.php?type=movie" class="<?= $type === 'movie' ? 'active' : '' ?>"><i class="fas fa-film"></i> Movies</a></li>
      <li><a href="index.php?type=series" class="<?= $type === 'series' ? 'active' : '' ?>"><i class="fas fa-tv"></i> Series</a></li>
      <li><a href="index.php?type=anime" class="<?= $type === 'anime' ? 'active' : '' ?>"><i class="fas fa-dragon"></i> Anime</a></li>
      <li><a href="request.php" class="btn-request-nav"><i class="fas fa-plus-circle"></i> Request</a></li>
    </ul>

    <button class="hamburger" id="hamburger" aria-label="Toggle menu" aria-expanded="false">
      <i class="fas fa-bars"></i>
    </button>
  </nav>
</header>

<!-- HERO SLIDER -->
<?php if (!empty($featured) && !$search): ?>
<section class="hero-section" aria-label="Featured movies">
  <?php foreach ($featured as $i => $f): ?>
  <?php $fSlug = !empty($f['slug']) ? $f['slug'] : makeSlug($f['title']); ?>
  <div class="hero-slide <?= $i === 0 ? 'active' : '' ?>" data-index="<?= $i ?>">
    <img class="hero-bg" src="<?= htmlspecialchars($f['backdrop_path'] ?? $f['poster_path'] ?? '') ?>"
         alt="" aria-hidden="true" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
    <div class="hero-overlay"></div>
    <div class="hero-content">
      <div class="hero-meta">
        <span class="hero-badge"><?= strtoupper($f['content_type']) ?></span>
        <?php if ($f['vote_average']): ?>
        <span class="hero-rating"><i class="fas fa-star"></i> <?= number_format($f['vote_average'], 1) ?></span>
        <?php endif; ?>
        <?php if ($f['release_date']): ?><span class="hero-year"><?= date('Y', strtotime($f['release_date'])) ?></span><?php endif; ?>
        <span class="hero-quality"><?= htmlspecialchars($f['quality']) ?></span>
      </div>
      <h2 class="hero-title"><?= htmlspecialchars($f['title']) ?></h2>
      <?php if ($f['overview']): ?>
      <p class="hero-desc"><?= htmlspecialchars(substr($f['overview'], 0, 180)) ?>...</p>
      <?php endif; ?>
      <div class="hero-actions">
        <a href="movie.php?slug=<?= urlencode($fSlug) ?>" class="btn btn-primary">
          <i class="fas fa-play"></i> Watch Now
        </a>
        <a href="movie.php?slug=<?= urlencode($fSlug) ?>" class="btn btn-outline">
          <i class="fas fa-info-circle"></i> Details
        </a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="hero-dots" role="tablist" aria-label="Slide navigation">
    <?php foreach ($featured as $i => $f): ?>
    <button class="hero-dot <?= $i === 0 ? 'active' : '' ?>" data-slide="<?= $i ?>"
            role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="Slide <?= $i+1 ?>"></button>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- MAIN BODY -->
<div class="site-body">
  <main class="main-content" id="main-content">

    <!-- Genre Tabs -->
    <?php if (!$search): ?>
    <div class="genre-tabs" role="tablist" aria-label="Filter by genre">
      <button class="genre-tab <?= !$genre && !$type ? 'active' : '' ?>"
              onclick="location.href='index.php'" role="tab">All</button>
      <button class="genre-tab <?= $type === 'movie' ? 'active' : '' ?>"
              onclick="location.href='index.php?type=movie'" role="tab">
        <i class="fas fa-film"></i> Movies</button>
      <button class="genre-tab <?= $type === 'series' ? 'active' : '' ?>"
              onclick="location.href='index.php?type=series'" role="tab">
        <i class="fas fa-tv"></i> Series</button>
      <button class="genre-tab <?= $type === 'anime' ? 'active' : '' ?>"
              onclick="location.href='index.php?type=anime'" role="tab">
        <i class="fas fa-dragon"></i> Anime</button>
      <span style="width:1px;background:var(--color-border-default);flex-shrink:0;"></span>
      <?php foreach ($allGenres as $g): ?>
      <button class="genre-tab <?= $genre === $g ? 'active' : '' ?>"
              onclick="location.href='index.php?genre=<?= urlencode($g) ?>'" role="tab">
              <?= htmlspecialchars($g) ?></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Section Header -->
    <div class="section-header">
      <h1 class="section-title">
        <?php if ($search): ?>
          Search: "<?= htmlspecialchars($search) ?>"
        <?php elseif ($genre): ?>
          <?= htmlspecialchars($genre) ?> Movies
        <?php elseif ($type): ?>
          <?= ucfirst($type) ?>s
        <?php else: ?>
          Latest Releases
        <?php endif; ?>
      </h1>
      <span class="live-badge"><?= $total ?> titles</span>
    </div>

    <!-- Movies Grid -->
    <?php if (empty($movies)): ?>
    <div class="empty-state" role="status">
      <i class="fas fa-film" aria-hidden="true"></i>
      <h3>No movies found</h3>
      <p>Try a different search or browse all movies</p>
      <a href="index.php" class="btn btn-primary" style="margin-top:16px;">Browse All</a>
    </div>
    <?php else: ?>
    <div class="movies-grid" id="moviesGrid" aria-label="Movie listings">
      <?php foreach ($movies as $m): ?>
      <?php $mSlug = !empty($m['slug']) ? $m['slug'] : makeSlug($m['title']); ?>
      <article class="movie-card" tabindex="0"
               onclick="location.href='movie.php?slug=<?= urlencode($mSlug) ?>'"
               onkeydown="if(event.key==='Enter')location.href='movie.php?slug=<?= urlencode($mSlug) ?>'"
               aria-label="<?= htmlspecialchars($m['title']) ?>">
        <div class="movie-poster-wrap">
          <?php if ($m['poster_path']): ?>
          <img class="movie-poster" src="<?= htmlspecialchars($m['poster_path']) ?>"
               alt="<?= htmlspecialchars($m['title']) ?> poster" loading="lazy">
          <?php else: ?>
          <div class="movie-poster-placeholder"><i class="fas fa-film"></i></div>
          <?php endif; ?>
          <span class="movie-quality-tag"><?= htmlspecialchars($m['quality']) ?></span>
          <?php if ($m['content_type'] !== 'movie'): ?>
          <span class="movie-type-tag"><?= strtoupper($m['content_type']) ?></span>
          <?php endif; ?>
          <?php if ($m['vote_average']): ?>
          <div class="movie-rating-overlay">
            <i class="fas fa-star" aria-hidden="true"></i>
            <?= number_format($m['vote_average'], 1) ?>
          </div>
          <?php endif; ?>
          <div class="movie-overlay" aria-hidden="true">
            <div class="play-icon"><i class="fas fa-play"></i></div>
          </div>
        </div>
        <div class="movie-info">
          <h3 class="movie-title"><?= htmlspecialchars($m['title']) ?></h3>
          <div class="movie-meta-row">
            <?php if ($m['release_date']): ?>
            <span><?= date('Y', strtotime($m['release_date'])) ?></span>
            <?php endif; ?>
            <?php if ($m['genres']): ?>
            <span>•</span>
            <span><?= htmlspecialchars(explode(',', $m['genres'])[0]) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Page navigation">
      <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
         class="page-btn" aria-label="Previous page">
        <i class="fas fa-chevron-left"></i>
      </a>
      <?php endif; ?>
      <?php
      $start = max(1, $page - 2);
      $end = min($totalPages, $page + 2);
      if ($start > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="page-btn">1</a><?php if ($start > 2): ?><span class="page-btn" style="cursor:default">…</span><?php endif; ?>
      <?php endif;
      for ($p = $start; $p <= $end; $p++): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
         class="page-btn <?= $p === $page ? 'active' : '' ?>"
         aria-current="<?= $p === $page ? 'page' : 'false' ?>"><?= $p ?></a>
      <?php endfor;
      if ($end < $totalPages): ?>
      <?php if ($end < $totalPages - 1): ?><span class="page-btn" style="cursor:default">…</span><?php endif; ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>" class="page-btn"><?= $totalPages ?></a>
      <?php endif; ?>
      <?php if ($page < $totalPages): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
         class="page-btn" aria-label="Next page">
        <i class="fas fa-chevron-right"></i>
      </a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Movie Request CTA -->
    <?php if (!$search): ?>
    <section class="request-section" aria-labelledby="requestHeading">
      <h2 id="requestHeading"><i class="fas fa-plus-circle" style="color:var(--color-accent)"></i> Can't Find Your Movie?</h2>
      <p>Request any movie, series, or anime and we'll try to add it as soon as possible.</p>
      <a href="request.php" class="btn btn-primary btn-lg">
        <i class="fas fa-paper-plane"></i> Submit a Request
      </a>
    </section>
    <?php endif; ?>

  </main>

  <!-- SIDEBAR -->
  <aside class="sidebar" aria-label="Sidebar">
    <!-- Trending Widget -->
    <?php if (!empty($trending)): ?>
    <div class="sidebar-widget" aria-labelledby="trendingTitle">
      <div class="sidebar-title" id="trendingTitle">
        <i class="fas fa-fire" style="color:var(--color-accent)"></i> Trending
      </div>
      <ul class="trending-list" role="list">
        <?php foreach ($trending as $i => $t): ?>
        <?php $tSlug = !empty($t['slug']) ? $t['slug'] : makeSlug($t['title']); ?>
        <li class="trending-item" tabindex="0"
            onclick="location.href='movie.php?slug=<?= urlencode($tSlug) ?>'"
            onkeydown="if(event.key==='Enter')location.href='movie.php?slug=<?= urlencode($tSlug) ?>'"
            role="listitem">
          <span class="trending-num <?= $i < 3 ? 'top' : '' ?>"><?= str_pad($i+1, 2, '0', STR_PAD_LEFT) ?></span>
          <?php if ($t['poster_path']): ?>
          <img class="trending-poster" src="<?= htmlspecialchars($t['poster_path']) ?>"
               alt="<?= htmlspecialchars($t['title']) ?>" loading="lazy">
          <?php endif; ?>
          <div class="trending-info">
            <strong><?= htmlspecialchars($t['title']) ?></strong>
            <span><?= $t['release_date'] ? date('Y', strtotime($t['release_date'])) : '' ?> • <?= htmlspecialchars($t['quality']) ?></span>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- Browse by Type -->
    <div class="sidebar-widget">
      <div class="sidebar-title"><i class="fas fa-filter" style="color:var(--color-accent)"></i> Browse by Type</div>
      <div style="padding:var(--space-5);display:flex;flex-direction:column;gap:var(--space-3);">
        <a href="index.php?type=movie" class="download-item" style="text-decoration:none">
          <div class="download-item-info"><i class="fas fa-film"></i><div><div class="download-label">Movies</div></div></div>
          <i class="fas fa-chevron-right" style="color:var(--color-text-tertiary)"></i>
        </a>
        <a href="index.php?type=series" class="download-item" style="text-decoration:none">
          <div class="download-item-info"><i class="fas fa-tv"></i><div><div class="download-label">Web Series</div></div></div>
          <i class="fas fa-chevron-right" style="color:var(--color-text-tertiary)"></i>
        </a>
        <a href="index.php?type=anime" class="download-item" style="text-decoration:none">
          <div class="download-item-info"><i class="fas fa-dragon"></i><div><div class="download-label">Anime</div></div></div>
          <i class="fas fa-chevron-right" style="color:var(--color-text-tertiary)"></i>
        </a>
        <a href="request.php" class="download-item" style="text-decoration:none;border-color:var(--color-accent)">
          <div class="download-item-info"><i class="fas fa-plus-circle" style="color:var(--color-accent)"></i><div><div class="download-label" style="color:var(--color-accent)">Request Movie</div></div></div>
          <i class="fas fa-chevron-right" style="color:var(--color-accent)"></i>
        </a>
      </div>
    </div>

    <!-- Ad Sidebar -->
    <?php if ($adsSidebar): ?>
    <div class="ad-widget"><?= $adsSidebar ?></div>
    <?php else: ?>
    <div class="ad-widget">
      <i class="fas fa-rectangle-ad" style="font-size:24px;display:block;margin-bottom:8px"></i>
      Advertisement
    </div>
    <?php endif; ?>
  </aside>
</div>

<!-- FOOTER -->
<footer class="site-footer" role="contentinfo">
  <div class="footer-grid">
    <div class="footer-brand">
      <a href="index.php" class="nav-logo" style="margin-bottom:0">
        <span class="logo-text"><?= htmlspecialchars($siteName) ?></span>
      </a>
      <p>Your ultimate destination for movies, web series, and anime. Download in one click — fast, free, and easy.</p>
    </div>
    <div>
      <div class="footer-col-title">Browse</div>
      <ul class="footer-links">
        <li><a href="index.php?type=movie"><i class="fas fa-film"></i> Movies</a></li>
        <li><a href="index.php?type=series"><i class="fas fa-tv"></i> Web Series</a></li>
        <li><a href="index.php?type=anime"><i class="fas fa-dragon"></i> Anime</a></li>
        <li><a href="request.php"><i class="fas fa-plus-circle"></i> Request</a></li>
      </ul>
    </div>
    <div>
      <div class="footer-col-title">Genres</div>
      <ul class="footer-links">
        <?php foreach (array_slice($allGenres, 0, 6) as $g): ?>
        <li><a href="index.php?genre=<?= urlencode($g) ?>"><i class="fas fa-tag"></i> <?= htmlspecialchars($g) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div>
      <div class="footer-col-title">Info</div>
      <ul class="footer-links">
        <li><a href="#"><i class="fas fa-shield-alt"></i> Privacy Policy</a></li>
        <li><a href="#"><i class="fas fa-file-contract"></i> Terms of Use</a></li>
        <li><a href="#"><i class="fas fa-envelope"></i> Contact</a></li>
        <li><a href="#"><i class="fas fa-exclamation-circle"></i> DMCA</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <p>&copy; <?= date('Y') ?> <a href="index.php"><?= htmlspecialchars($siteName) ?></a>. All rights reserved. For entertainment purposes only.</p>
    <p>Powered by TMDB API. This site does not store any files on its server.</p>
  </div>
</footer>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" role="dialog" aria-label="Image viewer" aria-modal="true">
  <img src="" alt="" id="lightboxImg">
  <button class="lightbox-close" id="lightboxClose" aria-label="Close image viewer">
    <i class="fas fa-times"></i>
  </button>
</div>

<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<script>
const hamburger   = document.getElementById('hamburger');
const navMenu     = document.getElementById('navMenu');
const navCloseBtn = document.getElementById('navCloseBtn');

function openNav() {
  navMenu.classList.add('open');
  hamburger.setAttribute('aria-expanded', 'true');
  hamburger.innerHTML = '<i class="fas fa-times"></i>';
  document.body.style.overflow = 'hidden';
}
function closeNav() {
  navMenu.classList.remove('open');
  hamburger.setAttribute('aria-expanded', 'false');
  hamburger.innerHTML = '<i class="fas fa-bars"></i>';
  document.body.style.overflow = '';
}

hamburger?.addEventListener('click', () => {
  navMenu.classList.contains('open') ? closeNav() : openNav();
});

// Close button inside fullscreen menu
navCloseBtn?.addEventListener('click', closeNav);

// Close when a link is tapped
navMenu?.querySelectorAll('a').forEach(a => {
  a.addEventListener('click', closeNav);
});

// Close on ESC
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNav(); });

// Close if user taps outside nav (on the dark overlay itself)
navMenu?.addEventListener('click', e => {
  if (e.target === navMenu) closeNav();
});

// On resize to desktop: reset nav state
window.addEventListener('resize', () => {
  if (window.innerWidth > 768) {
    closeNav();
  }
});

// Hero Slider
const slides = document.querySelectorAll('.hero-slide');
const dots = document.querySelectorAll('.hero-dot');
let currentSlide = 0, sliderTimer;
function goToSlide(n) {
  slides[currentSlide]?.classList.remove('active');
  dots[currentSlide]?.classList.remove('active');
  dots[currentSlide]?.setAttribute('aria-selected','false');
  currentSlide = (n + slides.length) % slides.length;
  slides[currentSlide]?.classList.add('active');
  dots[currentSlide]?.classList.add('active');
  dots[currentSlide]?.setAttribute('aria-selected','true');
}
function startSlider() { sliderTimer = setInterval(() => goToSlide(currentSlide + 1), 5000); }
dots.forEach((dot, i) => dot.addEventListener('click', () => { clearInterval(sliderTimer); goToSlide(i); startSlider(); }));
if (slides.length > 1) startSlider();

// Search
const searchInput = document.getElementById('navSearch');
const searchDropdown = document.getElementById('searchDropdown');
let st;
searchInput?.addEventListener('input', function() {
  clearTimeout(st);
  const q = this.value.trim();
  if (q.length < 2) { searchDropdown.classList.remove('active'); return; }
  st = setTimeout(async () => {
    try {
      const res = await fetch(`api/search.php?q=${encodeURIComponent(q)}`);
      const data = await res.json();
      if (!data.results?.length) { searchDropdown.classList.remove('active'); return; }
      searchDropdown.innerHTML = data.results.map(m => `
        <div class="search-result-item" onclick="location.href='movie.php?slug=${encodeURIComponent(m.slug||m.id)}'" role="option" tabindex="0">
          ${m.poster ? `<img src="${m.poster}" alt="${m.title}">` : '<div style="width:40px;height:56px;background:var(--color-surface-muted);border-radius:4px;flex-shrink:0"></div>'}
          <div class="search-result-info"><strong>${m.title}</strong><span>${m.year||''} • ${m.type||'Movie'}</span></div>
        </div>`).join('');
      searchDropdown.classList.add('active');
    } catch(e){}
  }, 300);
});
searchInput?.addEventListener('keydown', e => {
  if (e.key === 'Enter') { const q = searchInput.value.trim(); if (q) location.href=`index.php?q=${encodeURIComponent(q)}`; searchDropdown.classList.remove('active'); }
  if (e.key === 'Escape') searchDropdown.classList.remove('active');
});
document.getElementById('searchSubmit')?.addEventListener('click', e => {
  e.preventDefault(); const q = searchInput.value.trim(); if (q) location.href=`index.php?q=${encodeURIComponent(q)}`;
});
document.addEventListener('click', e => { if (!e.target.closest('.nav-search')) searchDropdown.classList.remove('active'); });

// Lightbox
const lightbox = document.getElementById('lightbox');
const lightboxImg = document.getElementById('lightboxImg');
window.openLightbox = function(src, alt) {
  lightboxImg.src = src; lightboxImg.alt = alt||'';
  lightbox.classList.add('active'); document.body.style.overflow='hidden';
};
document.getElementById('lightboxClose')?.addEventListener('click', ()=>{ lightbox.classList.remove('active'); document.body.style.overflow=''; });
lightbox?.addEventListener('click', e=>{ if(e.target===lightbox){ lightbox.classList.remove('active'); document.body.style.overflow=''; }});
document.addEventListener('keydown', e=>{ if(e.key==='Escape'&&lightbox.classList.contains('active')){ lightbox.classList.remove('active'); document.body.style.overflow=''; }});

window.showToast = function(msg, type='info', dur=3500) {
  const t = document.createElement('div');
  t.className=`toast ${type}`;
  t.innerHTML=`<i class="fas fa-info-circle"></i><span>${msg}</span>`;
  document.getElementById('toastContainer').appendChild(t);
  setTimeout(()=>t.remove(), dur);
};
</script>
</body>
</html>
