<?php
/**
*
* @package phpBB3
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

/**
* @ignore
*/
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);
include($phpbb_root_path . 'includes/functions_display.' . $phpEx);
include($phpbb_root_path . 'includes/bbcode.' . $phpEx);

// Start session management
$user->session_begin();
$auth->acl($user->data);
$user->setup(array('revisions', 'viewtopic'));

// Initialize data from URL
$post_id = $request->variable('p', 0);
// This defaults to a string on purpose
$revision_id = $request->variable('r', '');

// Initialize some defaults
$revision_from_id = $revision_to_id = 0;

if ($revision_id)
{
	preg_match('/([0-9]+)\.{3}([0-9]+)/', $revision_id, $match);
	if (!empty($match))
	{
		// When revisions.php?r=1...2
		// $match[0] contains 1...2
		// $match[1] and $match[2] contain 1 and 2 respectively
		$revision_from_id = $match[1];
		$revision_to_id = $match[2];
	}
	else
	{
		$revision_to_id = (int) $revision_id;
		if (!$revision_to_id)
		{
			trigger_error('NO_REVISIONS');
		}
	}

	// Now get the post ID from the to (ending) revision ID
	// since in the latter case, that's all we know
	$sql = 'SELECT post_id
		FROM ' . POST_REVISIONS_TABLE . '
		WHERE revision_id = ' . (int) $revision_to_id;
	$result = $db->sql_query($sql);
	$post_id = $db->sql_fetchfield('post_id');
	$db->sql_freeresult($result);

	if (!$post_id)
	{
		trigger_error('NO_REVISIONS');
	}
}

if ($post_id)
{
	$post = new phpbb_revisions_post($post_id);
	$post_data = $post->get('post_data');

	// We check one property that all posts should have
	// to make sure we specified an existing post
	if (empty($post_data['post_id']))
	{
		trigger_error('NO_POST');
	}

	$revisions = $post->load_revisions();

	// Get the total number of revisions
	$total_revisions = count($revisions);

	if (!$total_revisions)
	{
		trigger_error('NO_REVISIONS_POST');
	}
	
	if ($total_revisions > 1)
	{
		// Sort the revisions based on ID with a custom sort method
		uasort($revisions, array('phpbb_revisions_post', 'sort_post_revisions'));

		// Now we count the number of different users who have made revisions to the post
		$revision_users = array();
		foreach ($revisions as $revision)
		{
			if (!in_array($revision->get('user_id'), $revision_users))
			{
				$revision_users[] = $revision->get('user_id');
			}
		}
		$total_revision_users = count($revision_users);

		// If we have not been given an ending point, set it to the final revision
		$revision_to = $revision_to_id && isset($revisions[$revision_to_id]) ? $revisions[$revision_to_id] : end($revisions);
		// If we are using the final revision, we would like to have its ID available
		$revision_to_id = $revision_to->get('id');
		// If we have not been given a starting point, we will need to grab the previous revision's ID from the database.
		if (!$revision_from_id || !isset($revisions[$revision_from_id]))
		{
			$sql = 'SELECT revision_id
				FROM ' . POST_REVISIONS_TABLE . '
				WHERE revision_id < ' . (int) $revision_to_id . '
					AND post_id = ' . (int) $post_id . '
				ORDER BY revision_id DESC
				LIMIT 1';
			$result = $db->sql_query($sql);
			$revision_from_id = $db->sql_fetchfield('revision_id');
			$db->sql_freeresult($result);

		}
	}

	if ($total_revisions == 1 || !$revision_from_id)
	{
		// Stuff to do if we only have one revision to work with.

		// Basically, we just display the revision and explain that it's the only one.
	}
	else
	{
		$revision_from = $revisions[$revision_from_id];
		if (!class_exists('diff'))
		{
			include("{$phpbb_root_path}includes/diff/diff.{$phpEx}");
			include("{$phpbb_root_path}includes/diff/engine.{$phpEx}");
			include("{$phpbb_root_path}includes/diff/renderer.{$phpEx}");
		}

		$revision_from_text = $revision_from->get('text_decoded');
		$revision_from_subject = $revision_from->get('subject');
		$revision_to_text = $revision_to->get('text_decoded');
		$revision_to_subject = $revision_to->get('subject');

		$text_diff = new phpbb_revisions_diff($revision_from_text, $revision_to_text);
		$subject_diff = new phpbb_revisions_diff($revision_from_subject, $revision_to_subject);

		// Generate our diffs
		$r_text_diff = $text_diff->render() ?: ($user->lang('NO_DIFF') . '<br />' . $revision_to->get('text'));
		$r_subject_diff = bbcode_nl2br($subject_diff->render()) ?: ($user->lang('NO_DIFF') . '<br />' . $revision_to->get('subject'));

		// Consolidate some language strings
		$l_compare_summary = $user->lang('REVISION_COUNT', $total_revisions) . ' ' . $user->lang('BY') . ' ' . $user->lang('REVISION_USER_COUNT', $total_revision_users);
		$l_lines_added_removed = $user->lang('LINES_ADDED', $text_diff->count_added_lines()) . ' ' . strtolower($user->lang('AND')) . ' ' . $user->lang('LINES_REMOVED', $text_diff->count_deleted_lines());

		// We want to display a list of revisions with a few details about each
		foreach ($revisions as $revision)
		{
			// Only show revisions within the from -> to range
			if ($revision->get('id') >= $revision_from_id && $revision->get('id') <= $revision_to_id)
			{
				$template->assign_block_vars('revisions', array(
					'USERNAME'			=> $revision->get('username'),
					'USER_AVATAR'		=> $revision->get_avatar(20, 20),
					'DATE'				=> $user->format_date($revision->get('time')),
					'REASON'			=> $revision->get('reason'),
					'ID'				=> $revision->get('id'),

					'U_REVISION_VIEW'	=> append_sid("{$phpbb_root_path}revisions.$phpEx", array('r' => $revision->get('id'))),
				));
			}
		}

		$template->assign_vars(array(
			'DISPLAY_COMPARISON'	=> true,
			'TEXT_DIFF'				=> $r_text_diff,
			'SUBJECT_DIFF'			=> $r_subject_diff,

			'L_COMPARE_SUMMARY'		=> $l_compare_summary,
			'L_LAST_REVISION_TIME'	=> $user->lang('LAST_REVISION_TIME', $user->format_date($post_data['post_edit_time'])),
			'L_LINES_ADDED_REMOVED'	=> $l_lines_added_removed,
		));
	}
	// Ready the page for viewing
	page_header($user->lang('REVISIONS_COMPARE_TITLE'), false);

	$template->set_filenames(array(
		'body'		=> 'revisions_body.html',
	));

	page_footer();
}

// Generally, we won't get to this point, but just in case
trigger_error('NO_REVISIONS');