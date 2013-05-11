<?php

require("inc/global.php");

// only permit POST for some variables
$email = require_post("email", require_get("email", false));
$name = require_post("name", require_get("name", false));
$submit = require_post("submit", require_get("submit", false));
$openid = require_post("openid", require_get("openid", false));

$messages = array();
$errors = array();

if ($openid && $submit) {
	// to sign up with OpenID, we must first authenticate to see if the identity already exists
	try {
		require("inc/lightopenid/openid.php");
		$light = new LightOpenID(get_openid_host());

		if (!$light->mode) {
			// we still need to authenticate

			$light->identity = $openid;
			// The following two lines request email, full name, and a nickname
			// from the provider. Remove them if you dont need that data.
			// $light->required = array('contact/email');
			// $light->optional = array('namePerson', 'namePerson/friendly');

			// we want to add the openid identity URL to the return address
			// (the return URL is also verified in validate())
			$light->returnUrl = absolute_url(url_for('signup', array('openid' => $openid, 'submit' => 1, 'name' => $name, 'email' => $email)));

			redirect($light->authUrl());

		} else if ($light->mode == 'cancel') {
			// user has cancelled
			throw new Exception("User has cancelled authentication.");

		} else {
			// authentication is complete
			if ($light->validate()) {
				// we authenticate everything against a particular identity, not what is provided by the user
				// e.g. OpenID authenticating against http://foo.livejournal.com/?param=two#hash will return
				// an identity of http://foo.livejournal.com/.
				// print_r($light->getAttributes());

				$query = db()->prepare("SELECT * FROM users WHERE openid_identity=? LIMIT 1");
				$query->execute(array($light->identity));
				if ($user = $query->fetch()) {
					// a user already exists
					throw new Exception("An account for the OpenID identity '" . htmlspecialchars($light->identity) . "' already exists. Did you mean to <a href=\"" . url_for('login', array('openid' => $openid)) . "\">login instead</a>?");
				}

			} else {
				throw new Exception("OpenID validation was not successful: " . ($light->validate_error ? $light->validate_error : "Please try again."));
			}

		}

		// we can now proceed with creating a new user account
		$query = db()->prepare("INSERT INTO users SET
			name=:name, email=:email, openid_identity=:identity, created_at=NOW(), updated_at=NOW()");
		$user = array(
			"name" => $name,
			"email" => $email,
			"identity" => $light->identity,
		);
		$query->execute($user);
		$user['id'] = db()->lastInsertId();

		// try sending email
		if ($email) {
			send_email($email, $email, "signup", array(
				"email" => $email,
				"url" => absolute_url(url_for("unsubscribe", array('email' => $email, 'hash' => md5(get_site_config('unsubscribe_salt') . $email)))),
			));
		}

		// create default summary pages and cryptocurrencies and graphs contents
		reset_user_settings($user['id']);

		// success!
		$messages[] = "New account creation successful; you may now login.";

		// redirect
		set_temporary_messages($messages);
		redirect(url_for('login', array('openid' => $openid)));

	} catch (Exception $e) {
		$errors[] = $e->getMessage();
	}
}

require("layout/templates.php");
page_header("Signup", "page_signup", array('jquery' => true, 'common_js' => true));

?>
<h1>Signup</h1>

<?php require_template("signup"); ?>

<div class="columns2">
    <div class="column">

<div class="tabs" id="tabs_signup1">
	<ul class="tab_list">
		<li id="tab_signup1_openid">OpenID</li>
	</ul>
	<ul class="tab_groups">
		<li id="tab_signup1_openid_tab">
        <h2>Signup with OpenID</h2>

        <form action="<?php echo url_for('signup'); ?>" method="POST">
        <table class="login_form">
        <tr>
            <th>OpenID URL</th>
            <td><input type="text" name="openid" size="60" value="<?php if ($openid) echo htmlspecialchars($openid); ?>" maxlength="255" class="openid_url"></td>
        </tr>
        <tr>
            <th>Name</th>
            <td><input type="text" name="name" size="20" value="<?php if ($name) echo htmlspecialchars($name); ?>" maxlength="255"> (optional)</td>
        </tr>
        <tr>
            <th>Email</th>
            <td><input type="text" name="email" size="20" value="<?php if ($email) echo htmlspecialchars($email); ?>" maxlength="255"> (optional)</td>
        </tr>
        <tr>
        	<th></th>
        	<td><small>(Will be used to notify you if necessary.)</small></td>
        </tr>
		<tr>
			<td colspan="2" class="buttons">
				<input type="hidden" name="submit" value="1">
				<input type="submit" value="Signup">
			</td>
		</tr>
        </table>
        </form>
        </li>
    </ul>
</div>

    </div>

    <div class="column">

<?php $openid = get_default_openid_providers(); ?>
<div class="tabs" id="tabs_signup">
	<ul class="tab_list">
		<?php /* each <li> must not have any whitespace between them otherwise whitespace will appear when rendered */ ?>
		<?php foreach ($openid as $key => $data) {
			echo "<li id=\"tab_signup_$key\">" . htmlspecialchars($data[0]) . "</li>";
		} ?>
	</ul>

	<ul class="tab_groups">
	<?php foreach ($openid as $key => $data) { ?>
		<li id="tab_signup_<?php echo $key; ?>_tab">

        <h2>Signup with <?php echo htmlspecialchars($data[0]); ?></h2>

        <form action="<?php echo url_for('signup'); ?>" method="POST">
        <table class="login_form">
        <tr>
            <th>Name</th>
            <td><input type="text" name="name" size="32" value="<?php if ($name) echo htmlspecialchars($name); ?>" maxlength="255"> (optional)</td>
        </tr>
        <tr>
            <th>Email</th>
            <td><input type="text" name="email" size="32" value="<?php if ($email) echo htmlspecialchars($email); ?>" maxlength="255"> (optional)</td>
        </tr>
        <tr>
        	<th></th>
        	<td><small>(Will be used to notify you if necessary.)</small></td>
        </tr>
		<tr>
			<td colspan="2" class="buttons">
				<input type="hidden" name="openid" value="<?php echo htmlspecialchars($data[1]); ?>">
				<input type="hidden" name="submit" value="1">
				<input type="submit" value="Signup">
			</td>
		</tr>
        </table>
        </form>
        </li>
    <?php } ?>
    </ul>
</div>

    </div>
</div>
<div style="clear:both;"></div>

<script type="text/javascript">
$(document).ready(function() {
	initialise_tabs('#tabs_signup');
	initialise_tabs('#tabs_signup1');
});
</script>

<?php
page_footer();