<?php
declare(strict_types=1);
require_once __DIR__ . '/public.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('aida_session');
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','samesite'=>'Lax','path'=>'/']);
    session_start();
}
$joining = $formMode === 'join';
$page = $joining ? 'join.php' : 'contact.php';
$token = $_SESSION['enquiry_csrf'] ??= bin2hex(random_bytes(32));
$errors = [];
$values = [];
$interests = ['Membership','Research collaboration','Volunteering','Institutional partnership'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['name','email','phone','location','organisation','interest','expertise','motivation','message'] as $field) {
        $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    }
    $submittedToken = $_POST['csrf'] ?? null;
    if (!is_string($submittedToken) || !hash_equals($token, $submittedToken)) $errors[] = 'Your session expired. Please try submitting again.';
    if (!empty($_POST['website'])) $errors[] = 'Your submission could not be processed.';
    if (time() - ($_SESSION['enquiry_last_sent'] ?? 0) < 60) $errors[] = 'Please wait one minute before sending another submission.';
    if (!$values['name'] || strlen($values['name']) > 120) $errors[] = 'Enter your name (up to 120 characters).';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 190) $errors[] = 'Enter a valid email address.';
    if ($joining) {
        if (!$values['phone'] || strlen($values['phone']) > 40) $errors[] = 'Enter a phone number (up to 40 characters).';
        if (!$values['location'] || strlen($values['location']) > 160) $errors[] = 'Enter your city and country.';
        if (strlen($values['organisation']) > 180) $errors[] = 'Shorten your organisation name to 180 characters.';
        if (!in_array($values['interest'], $interests, true)) $errors[] = 'Choose how you would like to get involved.';
        foreach (['expertise'=>'experience','motivation'=>'reason for joining'] as $field=>$label) {
            if (!$values[$field] || strlen($values[$field]) > 5000) $errors[] = "Add your $label (up to 5,000 characters).";
        }
    } elseif (!$values['message'] || strlen($values['message']) > 5000) $errors[] = 'Enter a message (up to 5,000 characters).';
    if (($_POST['consent'] ?? '') !== 'yes') $errors[] = 'Please confirm that AIDA may use your details to respond to this submission.';
    if (!$errors) {
        try {
            require_once __DIR__ . '/bootstrap.php';
            if ($joining) {
                db()->prepare('INSERT INTO applications (name,email,phone,location,organisation,interest,expertise,motivation,consent_at) VALUES (?,?,?,?,?,?,?,?,NOW())')->execute([$values['name'],$values['email'],$values['phone'],$values['location'],$values['organisation'],$values['interest'],$values['expertise'],$values['motivation']]);
            } else {
                db()->prepare('INSERT INTO contact_messages (name,email,message) VALUES (?,?,?)')->execute([$values['name'],$values['email'],$values['message']]);
            }
            $_SESSION['enquiry_last_sent'] = time();
            $_SESSION['enquiry_success'][$formMode] = true;
            header('Location: ' . $page . '#form'); exit;
        } catch (Throwable $error) {
            error_log('AIDA enquiry storage failed: ' . $error->getMessage());
            $errors[] = 'We could not save your submission right now. Please try again later.';
        }
    }
}
$success = $_SESSION['enquiry_success'][$formMode] ?? false;
unset($_SESSION['enquiry_success'][$formMode]);
function form_escape(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function form_value(string $field): string { global $values; return form_escape($values[$field] ?? ''); }
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $joining ? 'Join AIDA' : 'Contact AIDA' ?> | Africa Innovation &amp; Development Academy</title>
<link rel="icon" type="image/png" href="Logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head><body class="institution-page">
<a class="skip-link" href="#main">Skip to content</a>
<header class="institution-header" id="top"><nav class="nav container" aria-label="Main navigation">
<a class="brand" href="index.php" aria-label="AIDA home"><img src="Logo.png" alt="Africa Innovation &amp; Development Academy"></a>
<button class="menu-toggle" aria-expanded="false" aria-controls="nav-links"><span></span><span></span><span></span><span class="sr-only">Open menu</span></button>
<div class="nav-links" id="nav-links"><a href="index.php#about">About us</a><a href="insights.php">Insights</a><a href="governance.php">Governance</a><a href="contact.php">Contact</a><a href="join.php" class="nav-cta" <?= $joining ? 'aria-current="page"' : '' ?>>Partner with us <span>↗</span></a></div>
</nav></header>
<main id="main"><section class="institution-hero enquiry-hero"><div class="container"><p class="eyebrow"><span></span><?= $joining ? 'JOIN THE AIDA COMMUNITY' : 'LET’S CONNECT' ?></p><h1><?= $joining ? 'Bring your expertise.<br>Help shape <em>what’s next.</em>' : 'A conversation can<br>make a <em>difference.</em>' ?></h1><p class="enquiry-intro"><?= $joining ? 'Apply to join AIDA and contribute to research, dialogue and inclusive development in Ghana and across Africa.' : 'Have a question, a research idea or a general enquiry? Send the AIDA team a message.' ?></p></div></section>
<section class="section enquiry-section"><div class="container enquiry-layout">
<aside class="enquiry-aside"><p class="section-kicker"><?= $joining ? 'YOUR CONTRIBUTION MATTERS' : 'GET IN TOUCH' ?></p><h2><?= $joining ? 'Different perspectives.<br>Shared <em>purpose.</em>' : 'We would like<br>to hear <em>from you.</em>' ?></h2>
<?php if ($joining): ?><p>Tell us about your interests and experience. Our team will review your application and contact you using the details you provide.</p><ol class="application-steps"><li><span>01</span>Complete your profile</li><li><span>02</span>Tell us how you can contribute</li><li><span>03</span>Submit for team review</li></ol><p class="enquiry-small">Submitting this form is an application. Membership or a partnership is confirmed separately by AIDA.</p><a class="text-link" href="contact.php">Just have a question? <span>→</span></a>
<?php else: ?>
<?php foreach (['contact_email'=>'Email','contact_phone'=>'Phone','contact_address'=>'Office'] as $key=>$label): $detail=cms_setting($key,''); if ($detail): ?><div class="contact-detail"><b><?= $label ?></b><p><?= form_escape($detail) ?></p></div><?php endif; endforeach; ?>
<p>For membership, volunteering or collaboration, please use our application form.</p><a class="text-link" href="join.php">Apply to join AIDA <span>→</span></a>
<?php endif; ?></aside>
<div class="enquiry-card" id="form">
<?php if ($success): ?><div class="enquiry-confirmation" role="status"><span aria-hidden="true">✓</span><h2><?= $joining ? 'Application received.' : 'Message received.' ?></h2><p><?= $joining ? 'Thank you for your interest in joining AIDA. Your application has been saved for the team to review.' : 'Thank you for getting in touch. Your message has been saved for the AIDA team.' ?></p><a class="button button-gold" href="index.php">Return to AIDA <span>→</span></a></div>
<?php else: ?><form method="post" action="<?= $page ?>#form" class="enquiry-form">
<div class="form-heading"><span class="form-mark" aria-hidden="true">↗</span><div><h3><?= $joining ? 'Your application' : 'Send a message' ?></h3><p>Fields marked * are required.</p></div></div>
<?php if ($errors): ?><div class="enquiry-errors" role="alert"><b>Please check your submission.</b><ul><?php foreach ($errors as $error): ?><li><?= form_escape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<input type="hidden" name="csrf" value="<?= form_escape($token) ?>">
<div class="enquiry-trap" aria-hidden="true"><label>Leave this field blank<input name="website" tabindex="-1" autocomplete="off"></label></div>
<fieldset><legend><?= $joining ? '01 / Personal details' : 'Your details' ?></legend><div class="enquiry-fields">
<label>Full name *<input name="name" autocomplete="name" maxlength="120" value="<?= form_value('name') ?>" required></label>
<label>Email address *<input type="email" name="email" autocomplete="email" maxlength="190" value="<?= form_value('email') ?>" required></label>
<?php if ($joining): ?><label>Phone number *<input type="tel" name="phone" autocomplete="tel" maxlength="40" value="<?= form_value('phone') ?>" required></label><label>City and country *<input name="location" maxlength="160" placeholder="e.g. Accra, Ghana" value="<?= form_value('location') ?>" required></label><label class="wide">Organisation or institution <span class="optional">Optional</span><input name="organisation" maxlength="180" value="<?= form_value('organisation') ?>"></label><?php endif; ?>
</div></fieldset>
<?php if ($joining): ?><fieldset><legend>02 / Your contribution</legend><label>How would you like to get involved? *<select name="interest" required><option value="">Choose an option</option><?php foreach ($interests as $interest): ?><option value="<?= form_escape($interest) ?>" <?= ($values['interest']??'')===$interest?'selected':'' ?>><?= form_escape($interest) ?></option><?php endforeach; ?></select></label><label>Tell us about your skills and experience *<textarea name="expertise" rows="4" maxlength="5000" placeholder="Your areas of expertise, research interests or relevant experience" required><?= form_value('expertise') ?></textarea></label><label>Why would you like to join AIDA? *<textarea name="motivation" rows="4" maxlength="5000" required><?= form_value('motivation') ?></textarea></label></fieldset>
<?php else: ?><label>Your message *<textarea name="message" rows="6" maxlength="5000" required><?= form_value('message') ?></textarea></label><?php endif; ?>
<label class="enquiry-consent"><input type="checkbox" name="consent" value="yes" required <?= ($_POST['consent']??'')==='yes'?'checked':'' ?>><span>I agree that AIDA may store my details and use them to review and respond to this <?= $joining ? 'application' : 'enquiry' ?>. *</span></label>
<p class="enquiry-small">Your submission is accessible to authorised AIDA administrators and editors. Please do not include passwords, identity documents or sensitive personal information.</p>
<button class="button button-gold" type="submit"><?= $joining ? 'Submit application' : 'Send message' ?> <span>→</span></button>
</form><?php endif; ?></div></div></section></main>
<footer><div class="container footer-inner"><a class="footer-brand" href="index.php"><img src="Logo.png" alt="AIDA"></a><p>Research. Dialogue. Impact.</p><p>© <?= date('Y') ?> Africa Innovation &amp; Development Academy.</p><a href="#top">Back to top ↑</a></div></footer><script src="assets/js/main.js"></script></body></html>
