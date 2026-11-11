<main id="phone-system" class="phone-system">
	<p id="phone-status" class="phone-status" role="status">…</p>
	<p id="phone-remote" class="phone-remote"></p>
	<form id="phone-form" class="phone-form">
		<input id="phone-number" type="tel" autocomplete="off" aria-label="<?php p($l->t('Number')); ?>">
		<button id="phone-call" type="submit" class="primary"><?php p($l->t('Call')); ?></button>
	</form>
	<p class="phone-actions">
		<button id="phone-answer" type="button" hidden><?php p($l->t('Answer')); ?></button>
		<button id="phone-hangup" type="button" hidden><?php p($l->t('Hang up')); ?></button>
		<button id="phone-mute" type="button" hidden><?php p($l->t('Mute')); ?></button>
	</p>
	<h3><?php p($l->t('Recent calls')); ?></h3>
	<ul id="phone-history" class="phone-history"></ul>
	<audio id="phone-audio" autoplay></audio>
</main>
