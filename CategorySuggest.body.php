<?php
/* CategorySuggest Mediawiki Extension
 *
 * @author Andreas Rindler (mediawiki at jenandi dot com)
 * @credits Jared Milbank, Leon Weber <leon.weber@leonweber.de>, Manuel Schneider <manuel.schneider@wikimedia.ch>, Daniel Friesen http://wiki-tools.com, Adam Franco <afranco@middlebury.edu>
 * @licence GNU General Public Licence 2.0 or later
 * @description Adds input box to edit and upload page which allows users to assign categories to the article. When a user starts typing the name of a category, the extension queries the database to find categories that match the user input."
 *
*/

if(!defined('MEDIAWIKI')) {
	die('This file is an extension to the MediaWiki software and cannot be used standalone.');
}

/*************************************************************************************/
## Entry point for the hook and main worker function for editing the page:
function fnCategorySuggestShowHook($m_isUpload = false, &$m_pageObj) {
	global $wgTitle, $wgRequest;
	global $wgScriptPath, $wgCategorySuggestCloud;

	# Get ALL categories from wiki:
//		$m_allCats = fnAjaxSuggestGetAllCategories();
	# Get the right member variables, depending on if we're on an upload form or not:
	if(!$m_isUpload) {
		# Check if page is subpage once to save method calls later:
		$m_isSubpage = $wgTitle->isSubpage();

		# Check if page has been submitted already to Preview or Show Changes
		$strCatsFromPreview = trim($wgRequest->getVal('txtSelectedCategories'), '; ');
		# Extract all categorylinks from PAGE:
		$catList = fnCategorySuggestGetPageCategories($m_pageObj);
		if($strCatsFromPreview){
		 	# Get cats from preview
			$catList = array_merge($catList,explode(';',$strCatsFromPreview));
		}
		# Check for duplicate
		$catList = array_unique($catList);
		# Never ever use editFormTextTop here as it resides outside the <form> so we will never get contents
		$m_place = 'editFormTextAfterWarn';
		# Print the localised title for the select box:
	} else	{
		# No need to get categories:
		$catList = array();

		# Place output at the right place:
		$m_place = 'uploadFormTextAfterSummary';
	}

	$catList = htmlspecialchars(str_replace('_',' ',implode(';', $catList)));
	if (!empty($catList)) {
		$catList .= ';';
	}
	$extCategoryField = '<script type="text/javascript">/*<![CDATA[*/ var categorysuggestSelect = "'. wfMessage('categorysuggest-select')->text() .'"; /*]]>*/</script>' .
		'<div id="categoryselectmaster"><div><b>' .wfMsg('categorysuggest-title'). '</b></div>' .
		'<table><caption>' . wfMsg('categorysuggest-subtitle'). '</caption><tbody>' .
		'<tr><th><label for="txtSelectedCategories">' .wfMsg('categorysuggest-boxlabel').':</label></th>' .
		'<td><div><input onkeyup="sendRequest(this,event);" onkeydown="return checkSelect(this, event)" autocomplete="off" type="text" name="txtSelectedCategories" id="txtSelectedCategories" length="150" value="'. $catList .'" />' .
		'<br/><div id="searchResults"></div></div></td>' .
		'<td></td></tr></tbody></table>' .
		'<input type="hidden" value="' . $wgCategorySuggestCloud . '" id="txtCSDisplayType" />' .
		'</div>';
	$m_pageObj->$m_place .= $extCategoryField;

	return true;
}

/*************************************************************************************/
## Entry point for the hook and main worker function for saving the page:
function fnCategorySuggestSaveHook($m_isUpload, $m_pageObj) {
	global $wgContLang;

	# Get localised namespace string:
	$m_catString = $wgContLang->getNsText(NS_CATEGORY);
	# Get some distance from the rest of the content:
	$m_text = "\n";

	#CHECK IF USER HAS SELECTED ANY CATEGORIES
	if($_POST['txtSelectedCategories']){
		$arrSelectedCats = explode(';',$_POST['txtSelectedCategories']);

	 	foreach($arrSelectedCats as $m_cat) {
	 	 	if($m_cat){
				$m_cat = Title::capitalize($m_cat, NS_CATEGORY);
				$m_text .= "\n[[". $m_catString .":" . trim($m_cat) . "]]";
			}
		}
		# If it is an upload we have to call a different method:
		if ($m_isUpload) {
			$m_pageObj->mUploadDescription .= $m_text;
		} else{
			$m_pageObj->textbox1 .= $m_text;
		}
	}

	# Return to the let MediaWiki do the rest of the work:
	return true;
}

/*************************************************************************************/
## Returns an array with the categories the articles is in.
## Also removes them from the text the user views in the editbox.
function fnCategorySuggestGetPageCategories($m_pageObj) {

	$foundCategories = array();
	# Get page contents:
	$m_pageText = $m_pageObj->textbox1;

	# Split the page on reserved tags so that we can avoid pulling categories out of them.
	$reservedTags = array('nowiki','noinclude','includeonly','onlyinclude');
	$stacklist = array();
	foreach($reservedTags as $tag){
		$stacklist[$tag] = array(); // Stack for reserved tags nested state.
	}
	$stacklist['template'] = array(); // Stack for template nested state.

	$m_pageText = preg_split('#(</?(?:'. implode('|',$reservedTags) . ')>|(?:\{\{|\}\}))#u', $m_pageText , null, PREG_SPLIT_DELIM_CAPTURE);
	foreach($m_pageText as $i => $block) {
		$preventCheck = true;
		$skip = 0;
		switch($block){
			// If we encounter a <nowiki>, <noinclude>, <includeonly> or <onlyinclude> tag, or a template opening string, add it to our stacks.
			case '{{' :
				$index = 'template';
			case '<nowiki>':
			case '<noinclude>':
			case '<includeonly>' :
			case '<onlyinclude>' :
				$index = ($index) ? $index : substr($block,1,-1);
				if(empty($stacklist['nowiki'])) $stacklist[$index][] = true;
				break;

			// If we encounter a closing </nowiki>, </noinclude>, </includeonly> or </onlyinclude> tag, or a template closing string, remove them from our stack and continue.
			case '}}' :
				$index = 'template';
			case '</nowiki>':
			case '</noinclude>':
			case '</includeonly>' :
			case '</onlyinclude>' :
				$index = ($index) ? $index : substr($block,2,-1);
				if((empty($stacklist['nowiki'])) || ($index == 'nowiki')) array_pop($stacklist[$index]);
				break;

			default :
				$preventCheck = false;
				foreach($stacklist as $index){
					if(!empty($index)) $preventCheck = true;
				}
				break;
		}
		# if it is a tag, or if a stack is open, there are  no categories to strip.
		$m_pageText[$i] = ($preventCheck) ? $block : fnCategorySuggestStripCats($block,$foundCategories);
	}
	# text recomposition
	$m_pageText = implode('',$m_pageText);

	//Place cleaned text back into the text box:
	$m_pageObj->textbox1 = trim($m_pageText);

	return $foundCategories;
}

function fnCategorySuggestStripCats($texttostrip,&$foundCategories){
	global $wgContLang;

	# Get localised namespace string:
	$m_catString = strtolower($wgContLang->getNsText(NS_CATEGORY));
	# The regular expression to find the category links:
	$m_pattern = "\[\[({$m_catString}|category):([^\]]*)\]\]";
	$m_replace = "$2";

	# Check linewise for category links:
	$texttostrip = explode("\n", $texttostrip);
	foreach($texttostrip as $index => $m_textLine) {
		# Filter line through pattern and store the result:
		$texttostrip[$index] = preg_replace_callback(
			"/{$m_pattern}/i",
			function($matches) use (&$foundCategories){
				//Set first letter to upper case to match MediaWiki standard
				$foundCategories[] = ucfirst($matches[2]);
				return "";
			},
			$m_textLine,
			-1,
			$count
		);
		if($count) $texttostrip[$index] = rtrim($texttostrip[$index]);
	}
	$texttostrip = implode("\n",$texttostrip);
	return $texttostrip;
}
