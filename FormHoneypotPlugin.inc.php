<?php

/**
 * @file plugins/generic/formHoneypot/FormHoneypotPlugin.inc.php
 *
 * Copyright (c) 2018 University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the LICENSE file.
 *
 * @class FormHoneypotPlugin
 * @ingroup plugins_generic_formHoneypot
 *
 * @brief Form Honeypot plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class FormHoneypotPlugin extends GenericPlugin {

	/**
	 * @var $availableElements array()
	 *  This array lists the possible input elements
	 */
	public $availableElements = array(
			'userUrl' => 'user.url',
			'phone' => 'user.phone',
			'fax' => 'user.fax',
			'gender' => 'user.gender',
			'mailingAddress' => 'common.mailingAddress',
			'affiliation' => 'user.affiliation',
			'signature' => 'user.signature',
			'biography' => 'user.biography',
			'createNewElement' => 'plugins.generic.formHoneypot.manager.settings.createNewElement',
	);

	/**
	 * @var $settingNames array()
	 * This array represents the fields on the settings form
	 */
	public $settingNames = array(
		'element' => 'string',
		'minimumTime' => 'int',
		'maximumTime' => 'int',
	);

	/**
	 * @var $formTimerSetting string
	 * This is the name of the setting used to track a users time during registration
	 */
	public $formTimerSetting = 'registrationTimer';

	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
		if ($success && $this->getEnabled()) {
			// Attach to the page footer
			HookRegistry::register('Templates::Common::Footer::PageFooter', array($this, 'insertHtml'));
			// Attach to the registration form validation
			HookRegistry::register('registrationform::validate', array($this, 'validateHoneypot'));
			// Attach to the registration form display
			HookRegistry::register('registrationform::display', array($this, 'initializeTimer'));
			// Add custom field if desired
			HookRegistry::register('TemplateManager::display', array($this, 'handleTemplateDisplay'));
			HookRegistry::register('registrationform::readuservars', array($this, 'handleUserVar'));
		}
		return $success;
	}
	
	/**
	 * Get the display name of this plugin.
	 * @return String
	 */
	function getDisplayName() {
		return __('plugins.generic.formHoneypot.displayName');
	}

	/**
	 * Get a description of the plugin.
	 * @return String
	 */
	function getDescription() {
		return __('plugins.generic.formHoneypot.description');
	}

	/**
	 * Set the page's breadcrumbs, given the plugin's tree of items
	 * to append.
	 * @param $isSubclass boolean
	 */
	function setBreadcrumbs($isSubclass = false) {
		$templateMgr =& TemplateManager::getManager();
		$pageCrumbs = array(
			array(
				Request::url(null, 'user'),
				'navigation.user'
			),
			array(
				Request::url(null, 'manager'),
				'user.role.manager'
			)
		);
		if ($isSubclass) {
			$pageCrumbs[] = array(
				Request::url(null, 'manager', 'plugins'),
				'manager.plugins'
			);
			$pageCrumbs[] = array(
				Request::url(null, 'manager', 'plugins', 'generic'),
				'plugins.categories.generic'
			);
		}

		$templateMgr->assign('pageHierarchy', $pageCrumbs);
	}


	/**
	 * Display verbs for the management interface.
	 * @return array of verb => description pairs
	 */
	function getManagementVerbs() {
		$verbs = array();
		if ($this->getEnabled()) {
			$verbs[] = array('settings', __('manager.plugins.settings'));
		}
		return parent::getManagementVerbs($verbs);
	}

	/**
	 * Insert Form Honeypot page tag to footer, if page is the user registration
	 * @param $hookName string Name of hook calling function
	 * @param $params array of smarty and output objects
	 * @return boolean
	 */
	function insertHtml($hookName, $params) {
		$output =& $params[2];
		$templateMgr =& TemplateManager::getManager();
		
		// journal is required to retreive settings
		$currentJournal = $templateMgr->get_template_vars('currentJournal');
		// element is required to set the honeypot
		if (isset($currentJournal)) {
			$element = $this->getElementSetting($currentJournal->getId());
		}
		// only operate on user registration
		$page = Request::getRequestedPage();
		$op = Request::getRequestedOp();
		if (isset($element) && $page === 'user' && substr($op, 0, 8) === 'register') {
			$templateMgr->assign('element', $element);
			$output .= $templateMgr->fetch($this->getTemplatePath() . 'pageTagScript.tpl');
		}
		return false;
	}

	/**
	 * Add honeypot validation to the user registration form
	 * @param $hookName string Name of hook calling function
	 * @param $params array of field, requirement, and message
	 * @return boolean
	 */
	function validateHoneypot($hookName, $params) {
		$journal =& Request::getJournal();
		if (isset($journal)) {
			$element = $this->getElementSetting($journal->getId());
			$minTime = $this->getSetting($journal->getId(), 'minimumTime');
			$maxTime = $this->getSetting($journal->getId(), 'maximumTime');
		}
		$form = $params[0];
		// If we have an element selected as a honeypot, check it 
		if (isset($element) && isset($form)) {
			$value = $form->getData($element);
			// Is it localized?
			if (is_array($value)) {
				$value = implode('', array_values($value));
			}
			// If not empty, flag an error
			if (!empty($value)) {
				$elementName = (isset($this->availableElements[$element]) ? $this->availableElements[$element] : 'plugins.generic.formHoneypot.leaveBlank');
				$message = __('plugins.generic.formHoneypot.doNotUseThisField', array('element' => __($elementName)));
				$form->addError(
					$element,
					$message
				);
			}
		}
		if ($form && $form->isValid() && ($minTime > 0 || $maxTime > 0)) {
			// Get the initial access to this form within this session
			$sessionManager =& SessionManager::getManager();
			$session =& $sessionManager->getUserSession();
			$started = $session->getSessionVar($this->getName()."::".$this->formTimerSetting);
			if (!$started || ($minTime > 0 && time() - $started < $minTime) || ($maxTime > 0 && time() - $started > $maxTime)) {
				$form->addError(
					'username',
					__('plugins.generic.formHoneypot.invalidSessionTime')
				);
			} else {
				$started = $session->unsetSessionVar($this->getName()."::".$this->formTimerSetting);
			}
		}
		return false;
	}

	/**
	 * Start monitoring for timing for form completion
	 * @param $hookName string Name of hook calling function
	 * @return boolean
	 */
	function initializeTimer($hookName) {
		// remember when this form was initialized for the user
		// we'll store it as a user setting on form execution
		$sessionManager =& SessionManager::getManager();
		$session =& $sessionManager->getUserSession();
		$started = $session->getSessionVar($this->getName()."::".$this->formTimerSetting);
		if (!$started) {
			$session->setSessionVar($this->getName()."::".$this->formTimerSetting, time());
		}
		return false;
	}

	/**
	 * Execute a management verb on this plugin
	 * @param $verb string
	 * @param $args array
	 * @param $message string Result status message
	 * @param $messageParams array Parameters for the message key
	 * @return boolean
	 */
	function manage($verb, $args, &$message, &$messageParams) {
		if (!parent::manage($verb, $args, $message, $messageParams)) {
			// If enabling this plugin, go directly to the settings
			if ($verb == 'enable') {
				$verb = 'settings';
			} else {
				return false;
			}
		}

		switch ($verb) {
			case 'settings':
				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));
				$journal =& Request::getJournal();

				$this->import('FormHoneypotSettingsForm');
				$form = new FormHoneypotSettingsForm($this, $journal->getId());
				// This assigns select options
				$templateMgr->assign('elementOptions', array_merge(array('' => ''), $this->availableElements));
				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						$user =& Request::getUser();
						import('classes.notification.NotificationManager');
						$notificationManager = new NotificationManager();
						$notificationManager->createTrivialNotification($user->getId());
						Request::redirect(null, 'manager', 'plugins', 'generic');
						return false;
					} else {
						$this->setBreadCrumbs(true);
						$form->display();
					}
				} else {
					$this->setBreadCrumbs(true);
					$form->initData();
					$form->display();
				}
				return true;
			default:
				// Unknown management verb
				assert(false);
				return false;
		}
	}

	/**
	 * Hook callback: register output filter to add a new registration field
	 * @see TemplateManager::display()
	 */
	function handleTemplateDisplay($hookName, $args) {
		$templateMgr =& $args[0];
		$template =& $args[1];

		switch ($template) {
			case 'user/register.tpl':
					$journal =& Request::getJournal();
					$element = $this->getSetting($journal->getId(), 'element');
					$customElement = $this->getSetting($journal->getId(), 'customElement');
					if ($element === 'createNewElement' && !empty($customElement)) {
						$templateMgr->register_outputfilter(array($this, 'addCustomElement'));
					}
				break;
		}
		return false;
	}

	/**
	 * Hook callback: assign user variable within Registration form
	 * @see Form::readUserVars()
	 */
	function handleUserVar($hookName, $args) {
		$form =& $args[0];
		$vars =& $args[1];
		$journal =& Request::getJournal();
		if (isset($journal)) {
			$element = $this->getSetting($journal->getId(), 'element');
			if ($element === 'createNewElement') {
				$element = $this->getElementSetting($journal->getId());
				$vars[] = $element;
			}
		}
		return false;
	}

	/**
	 * Output filter to create a new element in a registration form
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function addCustomElement($output, &$templateMgr) {
		if (preg_match('/<form id="registerForm"/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$formStart = $matches[0][1];
			$matches = array();
			if (preg_match_all('/(\s+<tr valign="top">\s+<td class="label">)/', $output, $matches, PREG_OFFSET_CAPTURE, $formStart)) {
				$placement = rand(0, count($matches[0]));
				$journal =& Request::getJournal();
				$element = $this->getSetting($journal->getId(), 'customElement');
				$templateMgr->assign('element', $element);
				$offset = $matches[0][$placement][1];
				$newOutput = substr($output, 0, $offset);
				$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'pageTagForm.tpl');;
				$newOutput .= substr($output, $offset);
				$output = $newOutput;
			}
		}
		$templateMgr->unregister_outputfilter('addCustomElement');
		return $output;
	}

	/**
	 * Get the actual name of the honeypot field
	 * @param $journalId int Journal ID
	 * @return $string
	 */
	function getElementSetting($journalId) {
		$element = $this->getSetting($journalId, 'element');
		$customElement = $this->getSetting($journalId, 'customElement');
		if ($element === 'createNewElement' && !empty($customElement)) {
			$element = $customElement;
		}
		return $element;
	}
}
?>
