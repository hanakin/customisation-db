<?php
/**
*
* @package Titania
* @copyright (c) 2008 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2
*
*/

/**
* @ignore
*/
if (!defined('IN_TITANIA'))
{
	exit;
}

if (!class_exists('titania_type_base'))
{
	include(TITANIA_ROOT . 'includes/types/base.' . PHP_EXT);
}

define('TITANIA_TYPE_STYLE', 2);

class titania_type_style extends titania_type_base
{
	/**
	 * The type id
	 *
	 * @var int type id (for custom types not specified in titania to start, please start with 10 in case we add any extra later)
	 */
	public $id = 2;
	
	/**
	 * The type name
	 *
	 * @var string (any lang key that includes the type should match this value)
	 */
	public $name = 'style';

	/**
	 * For the url slug
	 *
	 * @var string portion to be used in the URL slug
	 */
	public $url = 'style';

	/**
	 * The name of the field used to hold the number of this item in the authors table
	 *
	 * @var string author count
	 */
	public $author_count = 'author_styles';

	// Validation messages (for the PM)
	public $validation_subject = 'STYLE_VALIDATION';
	public $validation_message_approve = 'STYLE_VALIDATION_MESSAGE_APPROVE';
	public $validation_message_deny = 'STYLE_VALIDATION_MESSAGE_DENY';
	public $create_public = 'STYLE_CREATE_PUBLIC';
	public $reply_public = 'STYLE_REPLY_PUBLIC';
	public $update_public = 'STYLE_UPDATE_PUBLIC';
	public $clean_and_restore_root = true;
	public $root_search = '*';
	//public $upload_agreement = 'STYLE_UPLOAD_AGREEMENT';

	// License options
	public $license_options = array(
		'GPL v2.0',
		'GPL v3.0',
		'LGPL v2.1',
		'LGPL v3.0',
	);
	public $license_allow_custom = true;

	public function __construct()
	{
		$this->lang = phpbb::$user->lang['STYLE'];
		$this->langs = phpbb::$user->lang['STYLES'];
		$this->forum_database = titania::$config->forum_style_database;
		$this->forum_robot = titania::$config->forum_style_robot;
	}

	/**
	* Check auth level
	*
	* @param string $auth ('view', 'test', 'validate')
	* @return bool
	*/
	public function acl_get($auth)
	{
		switch ($auth)
		{
			// Can submit a style
			case 'submit' :
				return true;
			break;

			// Can view the style queue discussion
			case 'queue_discussion' :
				return phpbb::$auth->acl_get('u_titania_mod_style_queue_discussion');
			break;

			// Can view the style queue
			case 'view' :
				return phpbb::$auth->acl_get('u_titania_mod_style_queue');
			break;

			// Can validate styles in the queue
			case 'validate' :
				return phpbb::$auth->acl_get('u_titania_mod_style_validate');
			break;

			// Can moderate styles
			case 'moderate' :
				return phpbb::$auth->acl_gets(array('u_titania_mod_style_moderate', 'u_titania_mod_contrib_mod'));
			break;
			
			// Can edit ColorizeIt settings
			case 'colorizeit' :
			    return phpbb::$auth->acl_get('u_titania_mod_style_clr');
            break;
		}

		return false;
	}

	/**
	* Function that will be run when a revision of this type is uploaded
	*
	* @param $revision_attachment titania_attachment
	* @return array (error array, empty for no errors)
	*/
	public function upload_check($revision_attachment)
	{
		$zip_file = titania::$config->upload_path . '/' . utf8_basename($revision_attachment->attachment_directory) . '/' . utf8_basename($revision_attachment->physical_filename);
		$new_dir_name = phpbb::$user->data['user_id'] . '_' . microtime();

		$contrib_tools = new titania_contrib_tools($zip_file, $new_dir_name);

        foreach (scandir($contrib_tools->unzip_dir) as $item)
		{
			if ($item == '.' || $item == '..')
			{
				continue;
			}

			// Allow the license to be either in the main directory or under one subdirectory
			if (is_dir($contrib_tools->unzip_dir . '/' . $item))
			{
				foreach (scandir($contrib_tools->unzip_dir . '/' . $item) as $sub_item)
				{
		            if ($sub_item == 'license.txt')
					{
						// License file found
						$contrib_tools->remove_temp_files();
						return array();
					}
				}
			}

            if ($item == 'license.txt')
			{
				// License file found
				$contrib_tools->remove_temp_files();
				return array();
			}
        }

		$contrib_tools->remove_temp_files();

		return array(phpbb::$user->lang['LICENSE_FILE_MISSING']);
	}

	/**
	* Function to fix package name to ensure naming convention is followed
	*
	* @param $contrib Contribution object
	* @param $revision Revision object
	* @param $revision_attachment Attachment object
	* @param $root_dir Package root directory
	*
	* @return New root dir name
	*/		
	public function fix_package_name($contrib, $revision, $revision_attachment, $root_dir = false)
	{
		// If we managed to find a single parent directory, then we use that in the zip name, otherwise we fall back to using contrib_name_clean
		if ($root_dir !== false)
		{
			$new_real_filename = $root_dir . '_' . strtolower($revision->revision_version) . '.' . $revision_attachment->extension;
		}
		else
		{
			$new_real_filename = $contrib->contrib_name_clean . '_' . strtolower($revision->revision_version) . '.' . $revision_attachment->extension;
		}

		$revision_attachment->change_real_filename($new_real_filename);
		return $root_dir;
	}

	/**
	* @{inheritDoc}
	*/
	public function approve($contrib, $queue)
	{
		if (!phpbb::$request->is_set_post('style_demo_install'))
		{
			return;
		}

		$manager = phpbb::$container->get('phpbb.titania.style.demo.manager');
		$attachment = new titania_attachment(TITANIA_CONTRIB, $contrib->contrib_id);
		$revision = $queue->get_revision();
		$revision->load_phpbb_versions();
		$attachment->load($revision->attachment_id);
		$branch = $revision->phpbb_versions[0]['phpbb_version_branch'];
		$tool = new titania_contrib_tools($attachment->get_filepath($revision->attachment_id));

		if ($manager->configure($branch, $contrib, $tool))
		{
			$result = $manager->install();

			if (empty($result['error']))
			{
				$contrib->set_demo_url($branch, $manager->get_demo_url($branch, $result['id']));
				$contrib->submit();
			}
		}
	}

	/**
	* @{inheritDoc}
	*/
	public function display_validation_options($action)
	{
		if ($action != 'approve')
		{
			return;
		}

		phpbb::$template->assign_vars(array(
			'S_STYLE_DEMO_INSTALL'			=> true,
			'S_STYLE_DEMO_INSTALL_CHECKED'	=> phpbb::$request->variable('style_demo_install', false),
		));
	}
}