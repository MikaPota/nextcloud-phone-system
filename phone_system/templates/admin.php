<div id="phone-system-admin" class="section phone-system-admin">
	<h2><?php p($l->t('Phone System')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Connect the phone system and give users an extension. Each user signs in to their extension in the phone window.')); ?></p>
	<p><a class="button primary" href="<?php p($_['setupUrl']); ?>"><?php p($l->t('Create a new phone system')); ?></a></p>
	<form id="phone-admin-tenant" class="phone-admin-row">
		<label for="phone-admin-host"><?php p($l->t('Phone system address')); ?></label>
		<input id="phone-admin-host" type="text" placeholder="company.pbx.example.com" autocomplete="off">
		<button type="submit"><?php p($l->t('Save')); ?></button>
	</form>
	<p id="phone-admin-message" role="status"></p>
	<table id="phone-admin-users" class="phone-admin-users">
		<thead><tr><th><?php p($l->t('User')); ?></th><th><?php p($l->t('Extension')); ?></th><th><?php p($l->t('Web password of the extension')); ?></th><th></th></tr></thead>
		<tbody></tbody>
	</table>
</div>
