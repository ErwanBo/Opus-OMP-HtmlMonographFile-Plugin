<?php

/**
 * @file plugins/generic/htmlMonographFile/HtmlMonographFilePlugin.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class HtmlMonographFilePlugin
 * @ingroup plugins_generic_htmlMonographFile
 *
 * @brief Class for HtmlMonographFile plugin
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class HtmlMonographFilePlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		if (parent::register($category, $path, $mainContextId)) {
			if ($this->getEnabled($mainContextId)) {
				HookRegistry::register('CatalogBookHandler::view', array($this, 'viewCallback'));
				HookRegistry::register('CatalogBookHandler::download', array($this, 'downloadCallback'));
			}
			return true;
		}
		return false;
	}

	/**
	 * Install default settings on press creation.
	 * @return string
	 */
	function getContextSpecificPluginSettingsFile() {
		return $this->getPluginPath() . '/settings.xml';
	}

	/**
	 * Get the display name of this plugin.
	 * @return String
	 */
	function getDisplayName() {
		return __('plugins.generic.htmlMonographFile.displayName');
	}

	/**
	 * Get a description of the plugin.
	 */
	function getDescription() {
		return __('plugins.generic.htmlMonographFile.description');
	}

	/**
	 * Callback to view the HTML content rather than downloading.
	 * @param $hookName string
	 * @param $args array
	 * @return boolean
	 */
	function viewCallback($hookName, $params) {
		$submission =& $params[1];
		$publicationFormat =& $params[2];
		$submissionFile =& $params[3];
		$inline =& $params[4];
		$request = Application::get()->getRequest();

		$mimetype = $submissionFile->getData('mimetype');
		if ($submissionFile && ($mimetype == 'text/html' || $mimetype == 'text/x-tex')){
			foreach ($submission->getData('publications') as $publication) {
				if ($publication->getId() === $publicationFormat->getData('publicationId')) {
					$filePublication = $publication;
					break;
				}
			}
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign(array(
				'pluginUrl' => $request->getBaseUrl() . '/' . $this->getPluginPath(),
				'monograph' => $submission,
				'publicationFormat' => $publicationFormat,
				'downloadFile' => $submissionFile,
				'isLatestPublication' => $submission->getData('currentPublicationId') === $publicationFormat->getData('publicationId'),
				'filePublication' => $filePublication,
			));
			$templateMgr->display($this->getTemplateResource('display.tpl'));
			return true;
		}

		return false;
	}

	/**
	 * Callback to rewrite and serve HTML content.
	 * @param string $hookName
	 * @param array $args
	 */
	function downloadCallback($hookName, $params) {
		$submission =& $params[1];
		$publicationFormat =& $params[2];
		$submissionFile =& $params[3];
		$inline =& $params[4];
		$request = Application::get()->getRequest();

		$mimetype = $submissionFile->getData('mimetype');
		if ($submissionFile && ($mimetype == 'text/html' || $mimetype == 'text/x-tex')) {
			if (!HookRegistry::call('HtmlMonographFilePlugin::monographDownload', array(&$this, &$submission, &$publicationFormat, &$submissionFile, &$inline))) {
				echo $this->_getHTMLContents($request, $submission, $publicationFormat, $submissionFile);
				$returner = true;
				HookRegistry::call('HtmlMonographFilePlugin::monographDownloadFinished', array(&$returner));
				return true;
			}
		}

		return false;
	}
	/**
	 * Return string containing the contents of the HTML file.
	 * This function performs any necessary filtering, like image URL replacement.
	 * @param $request PKPRequest
	 * @param $monograph Monograph
	 * @param $publicationFormat PublicationFormat
	 * @param $submissionFile SubmissionFile
	 * @return string
	 */
	function _getHTMLContents($request, $monograph, $publicationFormat, $submissionFile) {
		$contents = Services::get('file')->fs->read($submissionFile->getData('path'));

		// Replace media file references
		import('lib.pkp.classes.submission.SubmissionFile'); // Constants
		$proofFiles = Services::get('submissionFile')->getMany([
			'submissionIds' => [$monograph->getId()],
			'fileStages' => [SUBMISSION_FILE_PROOF],
		]);
		$dependentFiles = Services::get('submissionFile')->getMany([
			'submissionIds' => [$monograph->getId()],
			'fileStages' => [SUBMISSION_FILE_DEPENDENT],
			'assocTypes' => [ASSOC_TYPE_SUBMISSION_FILE],
			'assocIds' => [$submissionFile->getId()],
		]);
		$embeddableFiles = array_merge(
			iterator_to_array($proofFiles),
			iterator_to_array($dependentFiles)
		);

		foreach ($embeddableFiles as $embeddableFile) {
			$fileUrl = $request->url(null, 'catalog', 'download', array($monograph->getBestId(), $publicationFormat->getBestId(), $embeddableFile->getBestId()), array('inline' => true));
			$pattern = preg_quote($embeddableFile->getLocalizedData('name'));

			$contents = preg_replace(
					'/([Ss][Rr][Cc]|[Hh][Rr][Ee][Ff]|[Dd][Aa][Tt][Aa])\s*=\s*"([^"]*' . $pattern . ')"/',
					'\1="' . $fileUrl . '"',
					$contents
			);

			// Replacement for Flowplayer
			$contents = preg_replace(
					'/[Uu][Rr][Ll]\s*\:\s*\'(' . $pattern . ')\'/',
					'url:\'' . $fileUrl . '\'',
					$contents
			);

			// Replacement for other players (tested with odeo; yahoo and google player won't work w/ OJS URLs, might work for others)
			$contents = preg_replace(
					'/[Uu][Rr][Ll]=([^"]*' . $pattern . ')/',
					'url=' . $fileUrl ,
					$contents
			);

		}

		// Perform replacement for ojs://... URLs
		$contents = preg_replace_callback(
				'/(<[^<>]*")[Oo][Mm][Pp]:\/\/([^"]+)("[^<>]*>)/',
				array(&$this, '_handleOmpUrl'),
				$contents
		);

		$templateMgr = TemplateManager::getManager($request);
		$contents = $templateMgr->loadHtmlGalleyStyles($contents, $embeddableFiles);

		// Perform variable replacement for press, publication format, site info
		$press = $request->getPress();
		$site = $request->getSite();

		$paramArray = array(
				'pressTitle' => $press->getLocalizedName(),
				'siteTitle' => $site->getLocalizedTitle(),
				'currentUrl' => $request->getRequestUrl()
		);

		foreach ($paramArray as $key => $value) {
			$contents = str_replace('{$' . $key . '}', $value, $contents);
		}
		
		$doc = new DOMDocument();
		$doc->loadHTML($contents);

		$script = $doc->getElementsByTagName("script")[0];
		while($script){
			$script->parentNode->removeChild($script);
			$script = $doc->getElementsByTagName("script")[0];
		}

		$doc_head = $doc->getElementsByTagName("head")[0];
		$doc_body = $doc->getElementsByTagName("body")[0];

		$doc_magnific_popup_css = $doc->createElement("link", "");
		$doc_magnific_popup_css->setAttribute("rel", "stylesheet");
		$doc_magnific_popup_css->setAttribute("type", "text/css");
		$doc_magnific_popup_css->setAttribute("href", "https://cdnjs.cloudflare.com/ajax/libs/magnific-popup.js/1.1.0/magnific-popup.min.css");
		$doc_head->appendChild($doc_magnific_popup_css);
		
		$additional_css = $doc->createElement("style", "
			body {
				background-color: #FFFFFF;
			}

			#mainnav {
				position: -webkit-sticky; 
				position: sticky; 
				top:0;
			}
			.manchette {
				float: right;
				margin-right: -23vw;
				clear: right;
				width: 20vw;
				position: relative;
				top: 0.3rem;
				font-size: 90%;
			}
		");
		$doc_head->appendChild($additional_css);

		$doc_jQuery = $doc->createElement("script", "");
		$doc_jQuery->setAttribute("src", "https://code.jquery.com/jquery-3.6.0.min.js");
		$doc_head->appendChild($doc_jQuery);
		
		$doc_mathJax = $doc->createElement("script", "");
		$doc_mathJax->setAttribute("src", "http://cdn.mathjax.org/mathjax/latest/MathJax.js?config=TeX-AMS-MML_HTMLorMML");
		$doc_head->appendChild($doc_mathJax);

		$doc_magnific_popup_js = $doc->createElement("script", "");
		$doc_magnific_popup_js->setAttribute("src", "https://cdnjs.cloudflare.com/ajax/libs/magnific-popup.js/1.1.0/jquery.magnific-popup.min.js");
		$doc_head->appendChild($doc_magnific_popup_js);

		$doc_new_script = $doc->createElement("script", "
			(function () {
				let figureNodes = document.querySelectorAll('figure');
				figureNodes.forEach(function(value, index, array) {
					value.id = 'fig'+ (index+1);
					value.setAttribute('class', 'magnificPopupImage');
					value.setAttribute('href', value.querySelector('img').getAttribute('src'));
					value.setAttribute('caption', value.querySelector('.txt_Legende').innerHTML);
				});
			})();
		");
		$doc_body->appendChild($doc_new_script);

		$doc_new_script = $doc->createElement("script", "
			$(document).ready(function($) {
				$('.magnificPopupImage').magnificPopup({
				gallery: {
					enabled: true
				},
				type:'image',
				image: {
					titleSrc: 'caption'
				}
				});
			});		 
		");
		$doc_body->appendChild($doc_new_script);

		$contents = $doc->saveHTML();
		return $contents;
	}

	function _handleOmpUrl($matchArray) {
		$request = Application::get()->getRequest();
		$url = $matchArray[2];
		$anchor = null;
		if (($i = strpos($url, '#')) !== false) {
			$anchor = substr($url, $i+1);
			$url = substr($url, 0, $i);
		}
		$urlParts = explode('/', $url);
		if (isset($urlParts[0])) switch(strtolower_codesafe($urlParts[0])) {
			case 'press':
				$url = $request->url(
					isset($urlParts[1]) ? $urlParts[1] : $request->getRequestedPressPath(),
					null, null, null, null, $anchor
				);
				break;
			case 'monograph':
				if (isset($urlParts[1])) {
					$url = $request->url(
						null, 'catalog', 'book',
						$urlParts[1], null, $anchor
					);
				}
				break;
			case 'sitepublic':
				array_shift($urlParts);
				import ('classes.file.PublicFileManager');
				$publicFileManager = new PublicFileManager();
				$url = $request->getBaseUrl() . '/' . $publicFileManager->getSiteFilesPath() . '/' . implode('/', $urlParts) . ($anchor?'#' . $anchor:'');
				break;
			case 'public':
				array_shift($urlParts);
				$press = $request->getPress();
				import ('classes.file.PublicFileManager');
				$publicFileManager = new PublicFileManager();
				$url = $request->getBaseUrl() . '/' . $publicFileManager->getContextFilesPath($press->getId()) . '/' . implode('/', $urlParts) . ($anchor?'#' . $anchor:'');
				break;
		}
		return $matchArray[1] . $url . $matchArray[3];
	}
}
