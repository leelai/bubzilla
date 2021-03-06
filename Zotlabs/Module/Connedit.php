<?php
namespace Zotlabs\Module;

/* @file connedit.php
 * @brief In this file the connection-editor form is generated and evaluated.
 *
 *
 */

use App;
use Zotlabs\Lib\Apps;
use Zotlabs\Lib\Crypto;
use Zotlabs\Lib\Libzot;
use Zotlabs\Lib\Libsync;
use Zotlabs\Daemon\Master;
use Zotlabs\Web\Controller;
use Zotlabs\Access\Permissions;
use Zotlabs\Access\PermissionLimits;
use Zotlabs\Web\HTTPHeaders;
use Zotlabs\Lib\Permcat;

require_once('include/socgraph.php');
require_once('include/selectors.php');
require_once('include/group.php');
require_once('include/photos.php');


class Connedit extends Controller {

	/* @brief Initialize the connection-editor
	 *
	 *
	 */

	function init() {

		if(! local_channel())
			return;

		if((argc() >= 2) && intval(argv(1))) {
			$r = q("SELECT abook.*, xchan.*
				FROM abook left join xchan on abook_xchan = xchan_hash
				WHERE abook_channel = %d and abook_id = %d and xchan_deleted = 0 LIMIT 1",
				intval(local_channel()),
				intval(argv(1))
			);
			if($r) {
				App::$poi = $r[0];
			}
		}


		$channel = App::get_channel();
		if($channel)
			head_set_icon($channel['xchan_photo_s']);

	}


	/* @brief Evaluate posted values and set changes
	 *
	 */

	function post() {

		if(! local_channel())
			return;

		$contact_id = intval(argv(1));
		if(! $contact_id)
			return;

		$channel = App::get_channel();

		// TODO if configured for hassle-free permissions, we'll post the form with ajax as soon as the
		// connection enable is toggled to a special autopost url and set permissions immediately, leaving
		// the other form elements alone pending a manual submit of the form. The downside is that there
		// will be a window of opportunity when the permissions have been set but before you've had a chance
		// to review and possibly restrict them. The upside is we won't have to warn you that your connection
		// can't do anything until you save the bloody form.

		$autopost = (((argc() > 2) && (argv(2) === 'auto')) ? true : false);

		$orig_record = q("SELECT * FROM abook WHERE abook_id = %d AND abook_channel = %d LIMIT 1",
			intval($contact_id),
			intval(local_channel())
		);

		if(! $orig_record) {
			notice( t('Could not access contact record.') . EOL);
			goaway(z_root() . '/connections');
			return; // NOTREACHED
		}

		call_hooks('contact_edit_post', $_POST);

		$vc = get_abconfig(local_channel(),$orig_record['abook_xchan'],'system','vcard');
		$vcard = (($vc) ? \Sabre\VObject\Reader::read($vc) : null);
		$serialised_vcard = update_vcard($_REQUEST,$vcard);
		if($serialised_vcard)
			set_abconfig(local_channel(),$orig_record[0]['abook_xchan'],'system','vcard',$serialised_vcard);

		if(intval($orig_record[0]['abook_self'])) {
			$autoperms = intval($_POST['autoperms']);
			$is_self = true;
		}
		else {
			$autoperms = null;
			$is_self = false;
		}


		$profile_id = ((array_key_exists('profile_assign',$_POST)) ? $_POST['profile_assign'] : $orig_record[0]['abook_profile']);

		if($profile_id) {
			$r = q("SELECT profile_guid FROM profile WHERE profile_guid = '%s' AND uid = %d LIMIT 1",
				dbesc($profile_id),
				intval(local_channel())
			);
			if(! count($r)) {
				notice( t('Could not locate selected profile.') . EOL);
				return;
			}
		}

		$abook_incl = ((array_key_exists('abook_incl',$_POST)) ? escape_tags($_POST['abook_incl']) : $orig_record[0]['abook_incl']);
		$abook_excl = ((array_key_exists('abook_excl',$_POST)) ? escape_tags($_POST['abook_excl']) : $orig_record[0]['abook_excl']);


		$hidden = intval($_POST['hidden']);

		$priority = intval($_POST['poll']);
		if($priority > 5 || $priority < 0)
			$priority = 0;

		if(! array_key_exists('closeness',$_POST)) {
			$_POST['closeness'] = 80;
		}
		$closeness = intval($_POST['closeness']);
		if($closeness < 0 || $closeness > 99) {
			$closeness = 80;
		}

		$rating = intval($_POST['rating']);
		if($rating < (-10))
			$rating = (-10);
		if($rating > 10)
			$rating = 10;

		$rating_text = trim(escape_tags($_REQUEST['rating_text']));

		$all_perms = Permissions::Perms();

		if($all_perms) {
			foreach($all_perms as $perm => $desc) {
				if(array_key_exists('perms_' . $perm, $_POST)) {
					set_abconfig($channel['channel_id'],$orig_record[0]['abook_xchan'],'my_perms',$perm,
						intval($_POST['perms_' . $perm]));
					if($autoperms) {
						set_pconfig($channel['channel_id'],'autoperms',$perm,intval($_POST['perms_' . $perm]));
					}
				}
				else {
					set_abconfig($channel['channel_id'],$orig_record[0]['abook_xchan'],'my_perms',$perm,0);
					if($autoperms) {
						set_pconfig($channel['channel_id'],'autoperms',$perm,0);
					}
				}
			}
		}

		if(! is_null($autoperms))
			set_pconfig($channel['channel_id'],'system','autoperms',$autoperms);

		$new_friend = false;

		// only store a record and notify the directory if the rating changed

		if(! $is_self) {

			$signed = $orig_record[0]['abook_xchan'] . '.' . $rating . '.' . $rating_text;
			$sig = base64url_encode(Crypto::sign($signed,$channel['channel_prvkey']));

			$rated = ((intval($rating) || strlen($rating_text)) ? true : false);

			$record = 0;

			$z = q("select * from xlink where xlink_xchan = '%s' and xlink_link = '%s' and xlink_static = 1 limit 1",
				dbesc($channel['channel_hash']),
				dbesc($orig_record[0]['abook_xchan'])
			);

			if($z) {
				if(($z[0]['xlink_rating'] != $rating) || ($z[0]['xlink_rating_text'] != $rating_text)) {
					$record = $z[0]['xlink_id'];
					$w = q("update xlink set xlink_rating = '%d', xlink_rating_text = '%s', xlink_sig = '%s', xlink_updated = '%s'
						where xlink_id = %d",
						intval($rating),
						dbesc($rating_text),
						dbesc($sig),
						dbesc(datetime_convert()),
						intval($record)
					);
				}
			}
			elseif($rated) {
				// only create a record if there's something to save
				$w = q("insert into xlink ( xlink_xchan, xlink_link, xlink_rating, xlink_rating_text, xlink_sig, xlink_updated, xlink_static ) values ( '%s', '%s', %d, '%s', '%s', '%s', 1 ) ",
					dbesc($channel['channel_hash']),
					dbesc($orig_record[0]['abook_xchan']),
					intval($rating),
					dbesc($rating_text),
					dbesc($sig),
					dbesc(datetime_convert())
				);
				$z = q("select * from xlink where xlink_xchan = '%s' and xlink_link = '%s' and xlink_static = 1 limit 1",
					dbesc($channel['channel_hash']),
					dbesc($orig_record[0]['abook_xchan'])
				);
				if($z)
					$record = $z[0]['xlink_id'];
			}
		}

		if(($_REQUEST['pending']) && intval($orig_record[0]['abook_pending'])) {

			$new_friend = true;

			// @fixme it won't be common, but when you accept a new connection request
			// the permissions will now be that of your permissions role and ignore
			// any you may have set manually on the form. We'll probably see a bug if somebody
			// tries to set the permissions *and* approve the connection in the same
			// request. The workaround is to approve the connection, then go back and
			// adjust permissions as desired.

			$p = Permissions::connect_perms(local_channel());
			$my_perms = $p['perms'];
			if($my_perms) {
				foreach($my_perms as $k => $v) {
					set_abconfig($channel['channel_id'],$orig_record[0]['abook_xchan'],'my_perms',$k,$v);
				}
			}
		}

		$abook_pending = (($new_friend) ? 0 : $orig_record[0]['abook_pending']);



		$r = q("UPDATE abook SET abook_profile = '%s', abook_closeness = %d, abook_pending = %d,
			abook_incl = '%s', abook_excl = '%s'
			where abook_id = %d AND abook_channel = %d",
			dbesc($profile_id),
			intval($closeness),
			intval($abook_pending),
			dbesc($abook_incl),
			dbesc($abook_excl),
			intval($contact_id),
			intval(local_channel())
		);

		if($r)
			info( t('Connection updated.') . EOL);
		else
			notice( t('Failed to update connection record.') . EOL);

		if(! intval(App::$poi['abook_self'])) {
			if($new_friend) {
				Master::Summon( [ 'Notifier', 'permission_accept', $contact_id ] );
			}

			Master::Summon( [
				'Notifier',
				(($new_friend) ? 'permission_create' : 'permission_update'),
				$contact_id
			]);
		}

		if($new_friend) {
			$default_group = $channel['channel_default_group'];
			if($default_group) {
				require_once('include/group.php');
				$g = group_rec_byhash(local_channel(),$default_group);
				if($g)
					group_add_member(local_channel(),'',App::$poi['abook_xchan'],$g['id']);
			}

			// Check if settings permit ("post new friend activity" is allowed, and
			// friends in general or this friend in particular aren't hidden)
			// and send out a new friend activity

			$pr = q("select * from profile where uid = %d and is_default = 1 and hide_friends = 0",
				intval($channel['channel_id'])
			);
			if(($pr) && (! intval($orig_record[0]['abook_hidden'])) && (intval(get_pconfig($channel['channel_id'],'system','post_newfriend')))) {
				$xarr = [];

				$xarr['item_wall'] = 1;
				$xarr['item_origin'] = 1;
				$xarr['item_thread_top'] = 1;
				$xarr['owner_xchan'] = $xarr['author_xchan'] = $channel['channel_hash'];
				$xarr['allow_cid'] = $channel['channel_allow_cid'];
				$xarr['allow_gid'] = $channel['channel_allow_gid'];
				$xarr['deny_cid'] = $channel['channel_deny_cid'];
				$xarr['deny_gid'] = $channel['channel_deny_gid'];
				$xarr['item_private'] = (($xarr['allow_cid']||$xarr['allow_gid']||$xarr['deny_cid']||$xarr['deny_gid']) ? 1 : 0);

				$xarr['body'] = '[zrl=' . $channel['xchan_url'] . ']' . $channel['xchan_name'] . '[/zrl]' . ' ' . t('is now connected to') . ' ' . '[zrl=' . App::$poi['xchan_url'] . ']' . App::$poi['xchan_name'] . '[/zrl]';

				$xarr['body'] .= "\n\n\n" . '[zrl=' . App::$poi['xchan_url'] . '][zmg=80x80]' . App::$poi['xchan_photo_m'] . '[/zmg][/zrl]';

				post_activity_item($xarr);

			}


			// pull in a bit of content if there is any to pull in
			Master::Summon(array('Onepoll',$contact_id));

		}

		// Refresh the structure in memory with the new data

		$r = q("SELECT abook.*, xchan.*
			FROM abook left join xchan on abook_xchan = xchan_hash
			WHERE abook_channel = %d and abook_id = %d LIMIT 1",
			intval(local_channel()),
			intval($contact_id)
		);
		if($r) {
			App::$poi = $r[0];
		}

		if($new_friend) {
			$arr = array('channel_id' => local_channel(), 'abook' => App::$poi);
			call_hooks('accept_follow', $arr);
		}

		$this->connedit_clone($a);

		if(($_REQUEST['pending']) && (!$_REQUEST['done']))
			goaway(z_root() . '/connections/ifpending');

		return;

	}

	/* @brief Clone connection
	 *
	 *
	 */

	function connedit_clone(&$a) {

			if(! App::$poi)
				return;


			$channel = App::get_channel();

			$r = q("SELECT abook.*, xchan.*
				FROM abook left join xchan on abook_xchan = xchan_hash
				WHERE abook_channel = %d and abook_id = %d LIMIT 1",
				intval(local_channel()),
				intval(App::$poi['abook_id'])
			);
			if($r) {
				App::$poi = array_shift($r);
			}

			$clone = App::$poi;

			unset($clone['abook_id']);
			unset($clone['abook_account']);
			unset($clone['abook_channel']);

			$abconfig = load_abconfig($channel['channel_id'],$clone['abook_xchan']);
			if($abconfig)
				$clone['abconfig'] = $abconfig;

			Libsync::build_sync_packet(0 /* use the current local_channel */, array('abook' => array($clone)));
	}

	/* @brief Generate content of connection edit page
	 *
	 *
	 */

	function get() {

		$sort_type = 0;
		$o = '';

		if(! local_channel()) {
			notice( t('Permission denied.') . EOL);
			return login();
		}

		$section = ((array_key_exists('section',$_REQUEST)) ? $_REQUEST['section'] : '');
		$channel = App::get_channel();

		$yes_no = array(t('No'),t('Yes'));

		$connect_perms = Permissions::connect_perms(local_channel());

		$o .= "<script>function connectDefaultShare() {
		\$('.abook-edit-me').each(function() {
			if(! $(this).is(':disabled'))
				$(this).prop('checked', false);
		});\n\n";
		foreach($connect_perms['perms'] as $p => $v) {
			if($v) {
				$o .= "\$('#me_id_perms_" . $p . "').prop('checked', true); \n";
			}
		}
		$o .= " }\n</script>\n";

		if(argc() == 3) {

			$contact_id = intval(argv(1));
			if(! $contact_id)
				return;

			$cmd = argv(2);

			$orig_record = q("SELECT abook.*, xchan.* FROM abook left join xchan on abook_xchan = xchan_hash
				WHERE abook_id = %d AND abook_channel = %d AND abook_self = 0 and xchan_deleted = 0 LIMIT 1",
				intval($contact_id),
				intval(local_channel())
			);

			if(! count($orig_record)) {
				notice( t('Could not access address book record.') . EOL);
				goaway(z_root() . '/connections');
			}

			if($cmd === 'update') {
				// pull feed and consume it, which should subscribe to the hub.
				Master::Summon(array('Poller',$contact_id));
				goaway(z_root() . '/connedit/' . $contact_id);

			}

			if($cmd === 'fetchvc') {
				$url = str_replace('/channel/','/profile/',$orig_record[0]['xchan_url']) . '/vcard';
				$recurse = 0;
				$x = z_fetch_url(zid($url),false,$recurse,['session' => true]);
				if($x['success']) {
					$h = new HTTPHeaders($x['header']);
					$fields = $h->fetch();
					if($fields) {
						foreach($fields as $y) {
							 if(array_key_exists('content-type',$y)) {
								$type = explode(';',trim($y['content-type']));
								if($type && $type[0] === 'text/vcard' && $x['body']) {
									$vc = \Sabre\VObject\Reader::read($x['body']);
									$vcard = $vc->serialize();
									if($vcard) {
										set_abconfig(local_channel(),$orig_record[0]['abook_xchan'],'system','vcard',$vcard);
										$this->connedit_clone($a);
									}
								}
							}
						}
					}
				}
				goaway(z_root() . '/connedit/' . $contact_id);
			}


			if($cmd === 'resetphoto') {
				q("update xchan set xchan_photo_date = '2001-01-01 00:00:00' where xchan_hash = '%s'",
					dbesc($orig_record[0]['xchan_hash'])
				);
				$cmd = 'refresh';
			}

			if($cmd === 'refresh') {
				if($orig_record[0]['xchan_network'] === 'zot6') {
					if(! Libzot::refresh($orig_record[0],App::get_channel()))
						notice( t('Refresh failed - channel is currently unavailable.') );
				}
				else {
					// if you are on a different network we'll force a refresh of the connection basic info
					Master::Summon(array('Notifier','permission_update',$contact_id));
				}
				goaway(z_root() . '/connedit/' . $contact_id);
			}

			if($cmd === 'block') {
				if(abook_toggle_flag($orig_record[0],ABOOK_FLAG_BLOCKED)) {
					$this->connedit_clone($a);
				}
				else
					notice(t('Unable to set address book parameters.') . EOL);
				goaway(z_root() . '/connedit/' . $contact_id);
			}

			if($cmd === 'ignore') {
				if(abook_toggle_flag($orig_record[0],ABOOK_FLAG_IGNORED)) {
					$this->connedit_clone($a);
				}
				else
					notice(t('Unable to set address book parameters.') . EOL);
				goaway(z_root() . '/connedit/' . $contact_id);
			}

			if($cmd === 'archive') {
				if(abook_toggle_flag($orig_record[0],ABOOK_FLAG_ARCHIVED)) {
					$this->connedit_clone($a);
				}
				else
					notice(t('Unable to set address book parameters.') . EOL);
				goaway(z_root() . '/connedit/' . $contact_id);
			}

			if($cmd === 'hide') {
				if(abook_toggle_flag($orig_record[0],ABOOK_FLAG_HIDDEN)) {
					$this->connedit_clone($a);
				}
				else
					notice(t('Unable to set address book parameters.') . EOL);
				goaway(z_root() . '/connedit/' . $contact_id);
			}

			// We'll prevent somebody from unapproving an already approved contact.
			// Though maybe somebody will want this eventually (??)

			if($cmd === 'approve') {
				if(intval($orig_record[0]['abook_pending'])) {
					if(abook_toggle_flag($orig_record[0],ABOOK_FLAG_PENDING)) {
						$this->connedit_clone($a);
					}
					else
						notice(t('Unable to set address book parameters.') . EOL);
				}
				goaway(z_root() . '/connedit/' . $contact_id);
			}


			if($cmd === 'drop') {

				contact_remove(local_channel(), $orig_record[0]['abook_id']);

				Master::Summon( [ 'Notifier', 'purge', local_channel(), $orig_record[0]['xchan_hash'] ] );

				Libsync::build_sync_packet(0 /* use the current local_channel */,
					array('abook' => array(array(
						'abook_xchan' => $orig_record[0]['abook_xchan'],
						'entry_deleted' => true))
					)
				);

				info( t('Connection has been removed.') . EOL );
				if(x($_SESSION,'return_url'))
					goaway(z_root() . '/' . $_SESSION['return_url']);
				goaway(z_root() . '/contacts');

			}
		}

		if(App::$poi) {

			$abook_prev = 0;
			$abook_next = 0;

			$contact_id = App::$poi['abook_id'];
			$contact = App::$poi;

			$cn = q("SELECT abook_id, xchan_name from abook left join xchan on abook_xchan = xchan_hash where abook_channel = %d and abook_self = 0 and xchan_deleted = 0 order by xchan_name",
				intval(local_channel())
			);

			if($cn) {
				$pntotal = count($cn);

				for($x = 0; $x < $pntotal; $x ++) {
					if($cn[$x]['abook_id'] == $contact_id) {
						if($x === 0)
							$abook_prev = 0;
						else
							$abook_prev = $cn[$x - 1]['abook_id'];
						if($x === $pntotal)
							$abook_next = 0;
						else
							$abook_next = $cn[$x +1]['abook_id'];
					}
				}
 			}

			$tools = array(

				'view' => array(
					'label' => t('View Profile'),
					'url'   => chanlink_cid($contact['abook_id']),
					'sel'   => '',
					'title' => sprintf( t('View %s\'s profile'), $contact['xchan_name']),
				),

				'refresh' => array(
					'label' => t('Refresh Permissions'),
					'url'   => z_root() . '/connedit/' . $contact['abook_id'] . '/refresh',
					'sel'   => '',
					'title' => t('Fetch updated permissions'),
				),

				'rephoto' => array(
					'label' => t('Refresh Photo'),
					'url'   => z_root() . '/connedit/' . $contact['abook_id'] . '/resetphoto',
					'sel'   => '',
					'title' => t('Fetch updated photo'),
				),

				'recent' => array(
					'label' => t('Recent Activity'),
					'url'   => z_root() . '/network/?f=&cid=' . $contact['abook_id'],
					'sel'   => '',
					'title' => t('View recent posts and comments'),
				),

				'block' => array(
					'label' => (intval($contact['abook_blocked']) ? t('Unblock') : t('Block')),
					'url'   => z_root() . '/connedit/' . $contact['abook_id'] . '/block',
					'sel'   => (intval($contact['abook_blocked']) ? 'active' : ''),
					'title' => t('Block (or Unblock) all communications with this connection'),
					'info'   => (intval($contact['abook_blocked']) ? t('This connection is blocked!') : ''),
				),

				'ignore' => array(
					'label' => (intval($contact['abook_ignored']) ? t('Unignore') : t('Ignore')),
					'url'   => z_root() . '/connedit/' . $contact['abook_id'] . '/ignore',
					'sel'   => (intval($contact['abook_ignored']) ? 'active' : ''),
					'title' => t('Ignore (or Unignore) all inbound communications from this connection'),
					'info'   => (intval($contact['abook_ignored']) ? t('This connection is ignored!') : ''),
				),

				'archive' => array(
					'label' => (intval($contact['abook_archived']) ? t('Unarchive') : t('Archive')),
					'url'   => z_root() . '/connedit/' . $contact['abook_id'] . '/archive',
					'sel'   => (intval($contact['abook_archived']) ? 'active' : ''),
					'title' => t('Archive (or Unarchive) this connection - mark channel dead but keep content'),
					'info'   => (intval($contact['abook_archived']) ? t('This connection is archived!') : ''),
				),

				'hide' => array(
					'label' => (intval($contact['abook_hidden']) ? t('Unhide') : t('Hide')),
					'url'   => z_root() . '/connedit/' . $contact['abook_id'] . '/hide',
					'sel'   => (intval($contact['abook_hidden']) ? 'active' : ''),
					'title' => t('Hide or Unhide this connection from your other connections'),
					'info'   => (intval($contact['abook_hidden']) ? t('This connection is hidden!') : ''),
				),

				'delete' => array(
					'label' => t('Delete'),
					'url'   => z_root() . '/connedit/' . $contact['abook_id'] . '/drop',
					'sel'   => '',
					'title' => t('Delete this connection'),
				),

			);


			if($contact['xchan_network'] === 'zot6') {
				$tools['fetchvc'] = [
					'label' => t('Fetch Vcard'),
					'url'    => z_root() . '/connedit/' . $contact['abook_id'] . '/fetchvc',
					'sel'   => '',
					'title' => t('Fetch electronic calling card for this connection')
				];
			}


			$sections = [];

			$sections['perms'] = [
					'label' => t('Permissions'),
					'url'   => z_root() . '/connedit/' . $contact['abook_id'] . '/?f=&section=perms',
					'sel'   => '',
					'title' => t('Open Individual Permissions section by default'),
			];

			$self = false;

			if(intval($contact['abook_self'])) {
				$self = true;
				$abook_prev = $abook_next = 0;
			}

			$vc = get_abconfig(local_channel(),$contact['abook_xchan'],'system','vcard');

			$vctmp = (($vc) ? \Sabre\VObject\Reader::read($vc) : null);
			$vcard = (($vctmp) ? get_vcard_array($vctmp,$contact['abook_id']) : [] );
			if(! $vcard)
				$vcard['fn'] = $contact['xchan_name'];


			$tpl = get_markup_template("abook_edit.tpl");

			if(Apps::system_app_installed(local_channel(),'Affinity Tool')) {

				$sections['affinity'] = [
					'label' => t('Affinity'),
					'url'   => z_root() . '/connedit/' . $contact['abook_id'] . '/?f=&section=affinity',
					'sel'   => '',
					'title' => t('Open Set Affinity section by default'),
				];

				$labels = [
					t('Me'),
					t('Family'),
					t('Friends'),
					t('Acquaintances'),
					t('All')
				];
				call_hooks('affinity_labels',$labels);
				$label_str = '';

				if($labels) {
					foreach($labels as $l) {
						if($label_str) {
							$label_str .= ", '|'";
							$label_str .= ", '" . $l . "'";
						}
						else
							$label_str .= "'" . $l . "'";
					}
				}

				$slider_tpl = get_markup_template('contact_slider.tpl');

				$slideval = intval($contact['abook_closeness']);

				$slide = replace_macros($slider_tpl,array(
					'$min' => 1,
					'$val' => $slideval,
					'$labels' => $label_str,
				));
			}

			if(feature_enabled(local_channel(),'connfilter')) {
				$sections['filter'] = [
					'label' => t('Filter'),
					'url'   => z_root() . '/connedit/' . $contact['abook_id'] . '/?f=&section=filter',
					'sel'   => '',
					'title' => t('Open Custom Filter section by default'),
				];
			}

			$rating_val = 0;
			$rating_text = '';

			$xl = q("select * from xlink where xlink_xchan = '%s' and xlink_link = '%s' and xlink_static = 1",
				dbesc($channel['channel_hash']),
				dbesc($contact['xchan_hash'])
			);

			if($xl) {
				$rating_val = intval($xl[0]['xlink_rating']);
				$rating_text = $xl[0]['xlink_rating_text'];
			}

			$rating_enabled = get_config('system','rating_enabled');

			if($rating_enabled) {
				$rating = replace_macros(get_markup_template('rating_slider.tpl'),array(
					'$min' => -10,
					'$val' => $rating_val
				));
			}
			else {
				$rating = false;
			}


			$perms = array();
			$channel = App::get_channel();

			$global_perms = Permissions::Perms();

			$existing = get_all_perms(local_channel(),$contact['abook_xchan'],false);

			$unapproved = array('pending', t('Approve this connection'), '', t('Accept connection to allow communication'), array(t('No'),('Yes')));

			$multiprofs = ((feature_enabled(local_channel(),'multi_profiles')) ? true : false);

			if($slide && !$multiprofs)
				$affinity = t('Set Affinity');

			if(!$slide && $multiprofs)
				$affinity = t('Set Profile');

			if($slide && $multiprofs)
				$affinity = t('Set Affinity & Profile');

			$theirs = q("select * from abconfig where chan = %d and xchan = '%s' and cat = 'their_perms'",
					intval(local_channel()),
					dbesc($contact['abook_xchan'])
			);
			$their_perms = array();
			if($theirs) {
				foreach($theirs as $t) {
					$their_perms[$t['k']] = $t['v'];
				}
			}

			foreach($global_perms as $k => $v) {
				$thisperm = get_abconfig(local_channel(),$contact['abook_xchan'],'my_perms',$k);
//fixme

				$checkinherited = PermissionLimits::Get(local_channel(),$k);

				// For auto permissions (when $self is true) we don't want to look at existing
				// permissions because they are enabled for the channel owner
				if((! $self) && ($existing[$k]))
					$thisperm = "1";




				$perms[] = array('perms_' . $k, $v, ((array_key_exists($k,$their_perms)) ? intval($their_perms[$k]) : ''),$thisperm, 1, (($checkinherited & PERMS_SPECIFIC) ? '' : '1'), '', $checkinherited);
			}

			$pcat = new Permcat(local_channel());
			$pcatlist = $pcat->listing();
			$permcats = [];
			if($pcatlist) {
				foreach($pcatlist as $pc) {
					$permcats[$pc['name']] = $pc['localname'];
				}
			}

			$locstr = locations_by_netid($contact['xchan_hash']);
			if(! $locstr)
				$locstr = unpunify($contact['xchan_url']);

			$clone_warn = '';
			$clonable = in_array($contact['xchan_network'], ['zot6', 'rss']);
			if(! $clonable) {
				$clone_warn = '<strong>';
				$clone_warn .= ((intval($contact['abook_not_here']))
					? t('This connection is unreachable from this location.')
					: t('This connection may be unreachable from other channel locations.')
				);
				$clone_warn .= '</strong><br>' . t('Location independence is not supported by their network.');
			}



			if(intval($contact['abook_not_here']) && $unclonable)
				$not_here = t('This connection is unreachable from this location. Location independence is not supported by their network.');

			$o .= replace_macros($tpl, [
				'$header'         => (($self) ? t('Connection Default Permissions') : sprintf( t('Connection: %s'),$contact['xchan_name'])),
				'$autoperms'      => array('autoperms',t('Apply these permissions automatically'), ((get_pconfig(local_channel(),'system','autoperms')) ? 1 : 0), t('Connection requests will be approved without your interaction'), $yes_no),
				'$permcat'        => [ 'permcat', t('Permission role'), '', '<span class="loading invisible">' . t('Loading') . '<span class="jumping-dots"><span class="dot-1">.</span><span class="dot-2">.</span><span class="dot-3">.</span></span></span>',$permcats ],
				'$permcat_new'    => t('Add permission role'),
				'$permcat_enable' => Apps::system_app_installed(local_channel(), 'Permission Categories'),
				'$addr'           => unpunify($contact['xchan_addr']),
				'$primeurl'       => unpunify($contact['xchan_url']),
				'$section'        => $section,
				'$sections'       => $sections,
				'$vcard'          => $vcard,
				'$addr_text'      => t('This connection\'s primary address is'),
				'$loc_text'       => t('Available locations:'),
				'$locstr'         => $locstr,
				'$unclonable'     => $clone_warn,
				'$notself'        => (($self) ? '' : '1'),
				'$self'           => (($self) ? '1' : ''),
				'$autolbl'        => t('The permissions indicated on this page will be applied to all new connections.'),
				'$tools_label'    => t('Connection Tools'),
				'$tools'          => (($self) ? '' : $tools),
				'$lbl_slider'     => t('Slide to adjust your degree of friendship'),
				'$lbl_rating'     => t('Rating'),
				'$lbl_rating_label' => t('Slide to adjust your rating'),
				'$lbl_rating_txt' => t('Optionally explain your rating'),
				'$connfilter'     => feature_enabled(local_channel(),'connfilter'),
				'$connfilter_label' => t('Custom Filter'),
				'$incl'           => array('abook_incl',t('Only import posts with this text'), $contact['abook_incl'],t('words one per line or #tags or /patterns/ or lang=xx, leave blank to import all posts')),
				'$excl'           => array('abook_excl',t('Do not import posts with this text'), $contact['abook_excl'],t('words one per line or #tags or /patterns/ or lang=xx, leave blank to import all posts')),
				'$rating_text'    => array('rating_text', t('Optionally explain your rating'),$rating_text,''),
				'$rating_info'    => t('This information is public!'),
				'$rating'         => $rating,
				'$rating_val'     => $rating_val,
				'$slide'          => $slide,
				'$affinity'       => $affinity,
				'$pending_label'  => t('Connection Pending Approval'),
				'$is_pending'     => (intval($contact['abook_pending']) ? 1 : ''),
				'$unapproved'     => $unapproved,
				'$inherited'      => t('inherited'),
				'$submit'         => t('Submit'),
				'$lbl_vis2'       => sprintf( t('Please choose the profile you would like to display to %s when viewing your profile securely.'), $contact['xchan_name']),
				'$close'          => (($contact['abook_closeness']) ? $contact['abook_closeness'] : 80),
				'$them'           => t('Their Settings'),
				'$me'             => t('My Settings'),
				'$perms'          => $perms,
				'$permlbl'        => t('Individual Permissions'),
				'$permnote'       => t('Some permissions may be inherited from your channel\'s <a href="settings"><strong>privacy settings</strong></a>, which have higher priority than individual settings. You can <strong>not</strong> change those settings here.'),
				'$permnote_self'  => t('Some permissions may be inherited from your channel\'s <a href="settings"><strong>privacy settings</strong></a>, which have higher priority than individual settings. You can change those settings here but they wont have any impact unless the inherited setting changes.'),
				'$lastupdtext'    => t('Last update:'),
				'$last_update'    => relative_date($contact['abook_connected']),
				'$profile_select' => contact_profile_assign($contact['abook_profile']),
				'$multiprofs'     => $multiprofs,
				'$contact_id'     => $contact['abook_id'],
				'$name'           => $contact['xchan_name'],
				'$abook_prev'     => $abook_prev,
				'$abook_next'     => $abook_next,
				'$vcard_label'    => t('Details'),
				'$displayname'    => $displayname,
				'$name_label'     => t('Name'),
				'$org_label'      => t('Organisation'),
				'$title_label'    => t('Title'),
				'$tel_label'      => t('Phone'),
				'$email_label'    => t('Email'),
				'$impp_label'     => t('Instant messenger'),
				'$url_label'      => t('Website'),
				'$adr_label'      => t('Address'),
				'$note_label'     => t('Note'),
				'$mobile'         => t('Mobile'),
				'$home'           => t('Home'),
				'$work'           => t('Work'),
				'$other'          => t('Other'),
				'$add_card'       => t('Add Contact'),
				'$add_field'      => t('Add Field'),
				'$create'         => t('Create'),
				'$update'         => t('Update'),
				'$delete'         => t('Delete'),
				'$cancel'         => t('Cancel'),
				'$po_box'         => t('P.O. Box'),
				'$extra'          => t('Additional'),
				'$street'         => t('Street'),
				'$locality'       => t('Locality'),
				'$region'         => t('Region'),
				'$zip_code'       => t('ZIP Code'),
				'$country'        => t('Country')
			]);

			$arr = array('contact' => $contact,'output' => $o);

			call_hooks('contact_edit', $arr);

			return $arr['output'];

		}
	}
}
