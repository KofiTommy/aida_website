<?php
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$name = trim($_POST['name'] ?? '');
$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$message = trim($_POST['message'] ?? '');
$status = '';
if ($submitted) {
    if ($name && $email && $message) {
        $record = [date('c'), $name, $email, str_replace(["\r", "\n"], ' ', $message)];
        $storage = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storage)) { mkdir($storage, 0750, true); }
        $handle = fopen($storage . DIRECTORY_SEPARATOR . 'messages.csv', 'ab');
        if ($handle) {
            flock($handle, LOCK_EX);
            fputcsv($handle, $record);
            flock($handle, LOCK_UN);
            fclose($handle);
            $status = 'Thank you, ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '. Your message has been received.';
        } else {
            $status = 'We could not save your message just now. Please try again shortly.';
        }
    } else {
        $status = 'Please add your name, a valid email address, and a short message.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="Logo.png">
  <link rel="apple-touch-icon" href="Logo.png">
  <meta name="description" content="Africa Innovation & Development Academy — research, dialogue and impact for a thriving Africa.">
  <title>AIDA | Africa Innovation & Development Academy</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <a class="skip-link" href="#main">Skip to content</a>
  <header class="site-header" id="top">
    <nav class="nav container" aria-label="Main navigation">
      <a class="brand" href="#top" aria-label="AIDA home"><img src="Logo.png" alt="AIDA — Africa Innovation & Development Academy"></a>
      <button class="menu-toggle" aria-expanded="false" aria-controls="nav-links"><span></span><span></span><span></span><span class="sr-only">Open menu</span></button>
      <div class="nav-links" id="nav-links">
        <a href="#about">About us</a><a href="#work">Our work</a><a href="governance.php">Governance</a><a href="#contact" class="nav-cta">Partner with us <span>↗</span></a>
      </div>
    </nav>
  </header>

  <main id="main">
    <section class="hero">
      <div class="hero-orbit orbit-one"></div><div class="hero-orbit orbit-two"></div>
      <div class="container hero-grid row align-items-center g-5">
        <div class="hero-copy reveal col-lg-7">
          <p class="eyebrow"><span></span> Africa Innovation &amp; Development Academy</p>
          <h1>Ideas that move <em>Ghana</em> and Africa forward.</h1>
          <p class="hero-text">AIDA is a civil society think and do platform transforming evidence, dialogue and innovation into practical pathways for inclusive economic development.</p>
          <div class="hero-actions"><a class="button button-gold" href="#work">Explore our work <span>↓</span></a><a class="text-link" href="#about">Discover AIDA <span>→</span></a></div>
        </div>
        <div class="hero-art reveal col-lg-5">
          <div class="brand-stage"><img class="hero-logo" src="Logo.png" alt="Africa Innovation &amp; Development Academy logo"><p>Research &nbsp;|&nbsp; Dialogue &nbsp;|&nbsp; Impact</p></div>
        </div>
      </div>
      <div class="container hero-foot"><p>Building the conditions for innovation to become shared prosperity.</p><a href="#about" aria-label="Scroll to about">SCROLL <span>↓</span></a></div>
    </section>

    <section class="intro section" id="about">
      <div class="container intro-grid row g-5"><p class="section-kicker col-md-4">01 / WHO WE ARE</p><div class="col-md-8"><h2>A home for brave ideas and <em>better development.</em></h2><p class="lead">Africa Innovation &amp; Development Academy brings together curious minds, communities, institutions and decision makers to address the questions that shape Ghana’s economic future.</p><a class="text-link dark" href="#contact">Meet the academy <span>→</span></a></div></div>
      <div class="container values">
        <article><span class="number">01</span><h3>Evidence led</h3><p>We turn rigorous research into clear, useful insight that informs decisions.</p></article>
        <article><span class="number">02</span><h3>People centred</h3><p>We make room for local knowledge, lived experience and diverse voices.</p></article>
        <article><span class="number">03</span><h3>Action oriented</h3><p>We convene, test and connect ideas to create meaningful change.</p></article>
      </div>
    </section>

    <section class="executive-band" aria-label="AIDA perspective"><div class="container executive-band-inner"><p class="band-label">AIDA PERSPECTIVE <span></span> GHANA</p><p class="band-statement">Evidence gives us <em>clarity.</em> Dialogue gives us <em>direction.</em> Impact gives us <em>purpose.</em></p><a href="#approach" class="band-link">How AIDA works <span>→</span></a></div></section>

    <section class="work section" id="work"><div class="container">
      <div class="section-heading"><div><p class="section-kicker light">02 / WHAT WE DO</p><h2>From questions to <em>collective action.</em></h2></div><p>We work at the intersection of research, innovation and public dialogue where lasting economic transformation begins.</p></div>
      <div class="work-grid row g-0">
        <article class="work-card featured col-md-6 col-lg-5"><div class="icon">⌁</div><p class="card-label">AIDA RESEARCH</p><h3>Research that sees the whole picture.</h3><p>Independent analysis on the economic, social and institutional challenges shaping Ghana and the wider continent.</p><a href="#contact">Learn more <span>→</span></a></article>
        <article class="work-card col-md-6 col-lg"><div class="icon">◌</div><p class="card-label">AIDA DIALOGUES</p><h3>Conversations that open possibilities.</h3><p>Inclusive spaces where citizens, experts and leaders can exchange ideas and build common ground.</p><a href="#contact">Learn more <span>→</span></a></article>
        <article class="work-card col-md-6 col-lg"><div class="icon">↗</div><p class="card-label">AIDA IMPACT</p><h3>Innovation that reaches people.</h3><p>Practical initiatives, partnerships and capacity building designed to turn insight into lasting impact.</p><a href="#contact">Learn more <span>→</span></a></article>
      </div>
    </div></section>

    <section class="approach section" id="approach"><div class="container approach-grid"><div><p class="section-kicker">03 / OUR APPROACH</p><h2>Progress is a team <em>sport.</em></h2><p class="lead">The strongest solutions are built with people, not simply for them. That is why AIDA connects perspectives across sectors and generations.</p></div><ol class="steps"><li><span>01</span><div><h3>Listen deeply</h3><p>Start with the realities, assets and ambitions already present in communities.</p></div></li><li><span>02</span><div><h3>Learn together</h3><p>Use research and dialogue to challenge assumptions and reveal opportunity.</p></div></li><li><span>03</span><div><h3>Move with purpose</h3><p>Build partnerships that carry promising ideas into lasting impact.</p></div></li></ol></div></section>

    <section class="cta"><div class="container cta-inner"><p class="eyebrow"><span></span> THE NEXT CHAPTER STARTS TOGETHER</p><h2>Let’s build an economy that works for <em>everyone.</em></h2><a class="button button-light" href="#contact">Start a conversation <span>→</span></a></div></section>

    <section class="contact section" id="contact"><div class="container contact-grid"><div><p class="section-kicker">04 / GET IN TOUCH</p><h2>Bring your<br><em>question.</em></h2><p class="lead">Whether you have a research idea, a partnership in mind or a conversation worth having, we would love to hear from you.</p><p class="contact-note">Ghana · Africa · The world</p></div><form class="contact-form" method="post" action="#contact" novalidate><div class="form-heading"><span class="form-mark">✦</span><div><h3>Send us a message</h3><p>We will be pleased to hear from you.</p></div></div><label>Your name<input name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></label><label>Email address<input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></label><label>What would you like to explore?<textarea name="message" rows="4" required><?= htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></label><button class="button button-gold" type="submit">Send your message <span>→</span></button><?php if ($status): ?><p class="form-status" role="status"><?= $status ?></p><?php endif; ?></form></div></section>
  </main>
  <footer><div class="container footer-inner"><a class="footer-brand" href="#top"><img src="Logo.png" alt="AIDA"></a><p>Research. Dialogue. Impact.</p><p>© <?= date('Y') ?> Africa Innovation &amp; Development Academy.</p><a href="#top">Back to top ↑</a></div></footer>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
  <script src="assets/js/main.js"></script>
</body></html>
