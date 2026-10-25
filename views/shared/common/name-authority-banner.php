<?php
$forms = $authority->getLanguageForms();
$name = $authority->getDisplayName();
?>
<div class="name-authority-banner">
    <h2 class="name-authority-banner-name" dir="auto"><?php echo html_escape($name); ?></h2>
    <?php if ($forms): ?>
    <ul class="name-authority-banner-forms">
        <?php foreach ($forms as $f): if ($f['text'] === $name) continue; $lang = NameAuthorityPlugin::langCode($f['language']); ?>
            <li><span class="name-authority-lang"><?php echo html_escape(__($f['language'])); ?></span>
                <span dir="auto"<?php echo $lang ? ' lang="' . $lang . '"' : ''; ?>><?php echo html_escape($f['text']); ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($showIds && ($authority->viaf_id !== '' || $authority->lc_id !== '')): ?>
    <p class="name-authority-banner-ids">
        <?php if ($authority->viaf_id !== ''): ?>
            <a href="https://viaf.org/viaf/<?php echo rawurlencode($authority->viaf_id); ?>/" rel="noopener" target="_blank">VIAF</a>
        <?php endif; ?>
        <?php if ($authority->lc_id !== ''): ?>
            <a href="https://id.loc.gov/authorities/<?php echo strpos($authority->lc_id, 'sh') === 0 ? 'subjects' : 'names'; ?>/<?php echo rawurlencode($authority->lc_id); ?>.html" rel="noopener" target="_blank"><?php echo __('Library of Congress'); ?></a>
        <?php endif; ?>
    </p>
    <?php endif; ?>
</div>
