<?php
$homepageItems=[];
try {
    if (function_exists('db')) {
        $homepageItems=db()->query("SELECT c.* FROM content c JOIN site_settings s ON s.setting_key=CONCAT('homepage_content_',c.id) AND s.setting_value='1' WHERE c.status='published' ORDER BY COALESCE(c.published_at,c.created_at) DESC, c.id DESC LIMIT 6")->fetchAll();
    }
} catch (Throwable $error) { error_log('AIDA homepage content could not be loaded.'); }
if (!$homepageItems) return;
$homepageLabels=['insight'=>'Insight','publication'=>'Publication','project'=>'Project','event'=>'Event','governance'=>'Governance'];
?>
<section class="section homepage-updates" id="latest" aria-labelledby="latest-title"><div class="container">
  <div class="homepage-updates-heading"><div><p class="section-kicker">RESEARCH &amp; UPDATES</p><h2 id="latest-title">Latest from AIDA</h2></div><a class="text-link" href="insights.php">View all resources &rarr;</a></div>
  <div class="resource-grid">
  <?php foreach($homepageItems as $entry): ?>
    <article class="resource-card">
      <?php if($entry['featured_image']): ?><img src="<?=e($entry['featured_image'])?>" alt="" loading="lazy"><?php endif; ?>
      <p class="section-kicker"><?=e($homepageLabels[$entry['content_type']]??'Resource')?> &middot; <?=e(date('d M Y',strtotime($entry['published_at']?:$entry['created_at'])))?></p>
      <h2><?=e($entry['title'])?></h2><p><?=e($entry['summary'])?></p>
      <div class="resource-actions"><a href="insights.php#content-<?=$entry['id']?>">Read more &rarr;</a>
        <?php if($entry['document_path']): ?><a href="<?=e($entry['document_path'])?>" target="_blank" rel="noopener">Open document &rarr;</a><?php endif; ?>
        <?php if($entry['video_url']): ?><a href="<?=e($entry['video_url'])?>" target="_blank" rel="noopener">Watch video &rarr;</a><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</div></section>
