<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_media
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');

/**
 * Media File Controller
 *
 * @package     Joomla.Administrator
 * @subpackage  com_media
 * @since       1.5
 */
class MediaControllerFile extends JControllerLegacy
{
	/**
	 * The folder we are uploading into
	 *
	 * @var   string
	 */
	protected $folder = '';

	/**
	 * Upload one or more files
	 *
	 * @return  boolean
	 *
	 * @since   1.5
	 */
	public function upload()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));
		$params = JComponentHelper::getParams('com_media');

		// Get some data from the request
		$files        = $this->input->files->get('Filedata', '', 'array');
		$return       = $this->input->post->get('return-url', null, 'base64');
		$this->folder = $this->input->get('folder', '', 'path');

		// Set the redirect
		if ($return)
		{
			$this->setRedirect(base64_decode($return) . '&folder=' . $this->folder);
		}

		// Authorize the user
		if (!$this->authoriseUser('create'))
		{
			return false;
		}

		if (($params->get('upload_maxsize', 0) * 1024 * 1024) != 0)
		{
			if (
				$_SERVER['CONTENT_LENGTH'] > ($params->get('upload_maxsize', 0) * 1024 * 1024)
				|| $_SERVER['CONTENT_LENGTH'] > (int) (ini_get('upload_max_filesize')) * 1024 * 1024
				|| $_SERVER['CONTENT_LENGTH'] > (int) (ini_get('post_max_size')) * 1024 * 1024
				|| (($_SERVER['CONTENT_LENGTH'] > (int) (ini_get('memory_limit')) * 1024 * 1024) && ((int) (ini_get('memory_limit')) != -1))
			)
			{
				JError::raiseWarning(100, JText::_('COM_MEDIA_ERROR_WARNFILETOOLARGE'));
				return false;
			}
		}

		// Perform basic checks on file info before attempting anything
		foreach ($files as &$file)
		{
			$file['name']     = JFile::makeSafe($file['name']);
			$file['filepath'] = JPath::clean(implode(DIRECTORY_SEPARATOR, array(COM_MEDIA_BASE, $this->folder, $file['name'])));

			if ($file['error'] == 1)
			{
				JError::raiseWarning(100, JText::_('COM_MEDIA_ERROR_WARNFILETOOLARGE'));
				return false;
			}

			if (($params->get('upload_maxsize', 0) * 1024 * 1024) != 0 && $file['size'] > ($params->get('upload_maxsize', 0) * 1024 * 1024))
			{
				JError::raiseNotice(100, JText::_('COM_MEDIA_ERROR_WARNFILETOOLARGE'));
				return false;
			}

			if (JFile::exists($file['filepath']))
			{
				// A file with this name already exists
				JError::raiseWarning(100, JText::_('COM_MEDIA_ERROR_FILE_EXISTS'));
				return false;
			}

			if (!isset($file['name']))
			{
				// No filename (after the name was cleaned by JFile::makeSafe)
				$this->setRedirect('index.php', JText::_('COM_MEDIA_INVALID_REQUEST'), 'error');
				return false;
			}
		}

		// Set FTP credentials, if given
		JClientHelper::setCredentialsFromRequest('ftp');
		JPluginHelper::importPlugin('content');
		$dispatcher	= JEventDispatcher::getInstance();

		foreach ($files as &$file)
		{
			// The request is valid
			$err = null;

			if (!MediaHelper::canUpload($file, $err))
			{
				// The file can't be uploaded

				return false;
			}

			// Trigger the onContentBeforeSave event.
			$object_file = new JObject($file);
			$result = $dispatcher->trigger('onContentBeforeSave', array('com_media.file', &$object_file, true));

			if (in_array(false, $result, true))
			{
				// There are some errors in the plugins
				JError::raiseWarning(100, JText::plural('COM_MEDIA_ERROR_BEFORE_SAVE', count($errors = $object_file->getErrors()), implode('<br />', $errors)));
				return false;
			}

			if (!JFile::upload($object_file->tmp_name, $object_file->filepath))
			{
				// Error in upload
				JError::raiseWarning(100, JText::_('COM_MEDIA_ERROR_UNABLE_TO_UPLOAD_FILE'));
				return false;
			}
			else
			{
				// Trigger the onContentAfterSave event.
				$dispatcher->trigger('onContentAfterSave', array('com_media.file', &$object_file, true));
				$this->setMessage(JText::sprintf('COM_MEDIA_UPLOAD_COMPLETE', substr($object_file->filepath, strlen(COM_MEDIA_BASE))));
			}
		}

		return true;
	}

	/**
	 * Check that the user is authorized to perform this action
	 *
	 * @param   string   $action - the action to be peformed (create or delete)
	 *
	 * @return  boolean
	 *
	 * @since   1.6
	 */
	protected function authoriseUser($action)
	{
		if (!JFactory::getUser()->authorise('core.' . strtolower($action), 'com_media'))
		{
			// User is not authorised
			JError::raiseWarning(403, JText::_('JLIB_APPLICATION_ERROR_' . strtoupper($action) . '_NOT_PERMITTED'));
			return false;
		}

		return true;
	}

	/**
	 * Deletes paths from the current path
	 *
	 * @return  boolean
	 *
	 * @since   1.5
	 */
	public function delete()
	{
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));

		// Get some data from the request
		$tmpl	= $this->input->get('tmpl');
		$paths	= $this->input->get('rm', array(), 'array');
		$folder = $this->input->get('folder', '', 'path');

		$redirect = 'index.php?option=com_media&folder=' . $folder;

		if ($tmpl == 'component')
		{
			// We are inside the iframe
			$redirect .= '&view=mediaList&tmpl=component';
		}

		$this->setRedirect($redirect);

		// Nothing to delete
		if (empty($paths))
		{
			return true;
		}

		// Authorize the user
		if (!$this->authoriseUser('delete'))
		{
			return false;
		}

		// Set FTP credentials, if given
		JClientHelper::setCredentialsFromRequest('ftp');

		JPluginHelper::importPlugin('content');
		$dispatcher	= JEventDispatcher::getInstance();

		$ret = true;

		foreach ($paths as $path)
		{
			if ($path !== JFile::makeSafe($path))
			{
				// filename is not safe
				$filename = htmlspecialchars($path, ENT_COMPAT, 'UTF-8');
				JError::raiseWarning(100, JText::sprintf('COM_MEDIA_ERROR_UNABLE_TO_DELETE_FILE_WARNFILENAME', substr($filename, strlen(COM_MEDIA_BASE))));
				continue;
			}

			$fullPath = JPath::clean(implode(DIRECTORY_SEPARATOR, array(COM_MEDIA_BASE, $folder, $path)));
			$object_file = new JObject(array('filepath' => $fullPath));

			if (is_file($object_file->filepath))
			{
				// Trigger the onContentBeforeDelete event.
				$result = $dispatcher->trigger('onContentBeforeDelete', array('com_media.file', &$object_file));
				if (in_array(false, $result, true))
				{
					// There are some errors in the plugins
					JError::raiseWarning(100, JText::plural('COM_MEDIA_ERROR_BEFORE_DELETE', count($errors = $object_file->getErrors()), implode('<br />', $errors)));
					continue;
				}

				$ret &= JFile::delete($object_file->filepath);

				// Trigger the onContentAfterDelete event.
				$dispatcher->trigger('onContentAfterDelete', array('com_media.file', &$object_file));
				$this->setMessage(JText::sprintf('COM_MEDIA_DELETE_COMPLETE', substr($object_file->filepath, strlen(COM_MEDIA_BASE))));
			}
			elseif (is_dir($object_file->filepath))
			{
				$contents = JFolder::files($object_file->filepath, '.', true, false, array('.svn', 'CVS', '.DS_Store', '__MACOSX', 'index.html'));

				if (empty($contents))
				{
					// Trigger the onContentBeforeDelete event.
					$result = $dispatcher->trigger('onContentBeforeDelete', array('com_media.folder', &$object_file));

					if (in_array(false, $result, true))
					{
						// There are some errors in the plugins
						JError::raiseWarning(100, JText::plural('COM_MEDIA_ERROR_BEFORE_DELETE', count($errors = $object_file->getErrors()), implode('<br />', $errors)));
						continue;
					}

					$ret &= JFolder::delete($object_file->filepath);

					// Trigger the onContentAfterDelete event.
					$dispatcher->trigger('onContentAfterDelete', array('com_media.folder', &$object_file));
					$this->setMessage(JText::sprintf('COM_MEDIA_DELETE_COMPLETE', substr($object_file->filepath, strlen(COM_MEDIA_BASE))));
				}
				else
				{
					// This makes no sense...
					JError::raiseWarning(100, JText::sprintf('COM_MEDIA_ERROR_UNABLE_TO_DELETE_FOLDER_NOT_EMPTY', substr($object_file->filepath, strlen(COM_MEDIA_BASE))));
				}
			}
		}

		return $ret;
	}
}
