<?php

namespace CookieWarning;

use Config;
use ConfigException;
use ExtensionRegistry;
use Html;
use MediaWiki;
use MediaWiki\MediaWikiServices;
use MobileContext;
use MWException;
use OutputPage;
use Skin;
use Title;
use User;
use WebRequest;

class Hooks {
	/**
	 * BeforeInitialize hook handler.
	 *
	 * If the disablecookiewarning POST data is send, disables the cookiewarning bar with a
	 * cookie or a user preference, if the user is logged in.
	 *
	 * @param Title &$title
	 * @param null &$unused
	 * @param OutputPage &$output
	 * @param User &$user
	 * @param WebRequest $request
	 * @param MediaWiki $mediawiki
	 * @throws MWException
	 */
	public static function onBeforeInitialize( Title &$title, &$unused, OutputPage &$output,
		User &$user, WebRequest $request, MediaWiki $mediawiki
	) {
		if ( !$request->wasPosted() || !$request->getVal( 'disablecookiewarning' ) ) {
			return;
		}

		if ( $user->isLoggedIn() ) {
			$user->setOption( 'cookiewarning_dismissed', 1 );
			$user->saveSettings();
		} else {
			$request->response()->setCookie( 'cookiewarning_dismissed', true );
		}
		$output->redirect( $request->getRequestURL() );
	}

	/**
	 * SiteNoticeAfter hook handler.
	 *
	 * Adds the CookieWarning information bar to the output html for mobile.
	 *
	 * @param string &$siteNotice
	 * @param Skin $skin
	 * @throws ConfigException
	 * @throws MWException
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public static function onSiteNoticeAfter(
		string &$siteNotice,
		Skin $skin
	) {
		/** @var Decisions $cookieWarningDecisions */
		$cookieWarningDecisions = MediaWikiServices::getInstance()
			->getService( 'CookieWarning.Decisions' );

		if ( !$cookieWarningDecisions->shouldShowCookieWarning( $skin->getContext() ) ) {
			return;
		}

		$isMobile = ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' ) &&
			MobileContext::singleton()->shouldDisplayMobileView();

		$siteNotice .= self::generateElements( $skin, $isMobile );
	}

	/**
	 * Generates the elements for the banner.
	 *
	 * @param Skin $skin
	 * @param bool $isMobile This will return true if using mobile site.
	 * @return string|null The html for cookie notice.
	 */
	private static function generateElements( Skin $skin, bool $isMobile ) {
		$moreLink = self::getMoreLink();

		if ( $moreLink ) {
			$moreLink = "\u{00A0}" . Html::element(
				'a',
				[ 'href' => $moreLink ],
				$skin->msg( 'cookiewarning-moreinfo-label' )->text()
			);
		}

		$form = Html::openElement( 'form', [ 'method' => 'POST' ] ) .
			Html::submitButton(
				$skin->msg( 'cookiewarning-ok-label' )->text(),
				[
					'name' => 'disablecookiewarning',
					'class' => 'mw-cookiewarning-dismiss'
				]
			) .
			Html::closeElement( 'form' );

		$cookieImage = Html::element(
			'div',
			[ 'class' => 'mw-cookiewarning-cimage' ],
			"\u{1F36A}"
		);

		return Html::openElement(
				'div',
				// banner-container marks this as a banner for Minerva
				// Note to avoid this class, in future we may want to make use of SiteNotice
				// or banner display
				[ 'class' => 'mw-cookiewarning-container banner-container' ]
			) .
			Html::openElement(
				'div',
				[ 'class' => 'mw-cookiewarning-text' ]
			) .
			( $isMobile ? $cookieImage : '' ) .
			Html::element(
				'span',
				[],
				$skin->msg( 'cookiewarning-info' )->text()
			) .
			$moreLink .
			Html::closeElement( 'div' ) .
			$form .
			Html::closeElement( 'div' );
	}

	/**
	 * Returns the target for the "More information" link of the cookie warning bar, if one is set.
	 * The link can be set by either (checked in this order):
	 *  - the configuration variable $wgCookieWarningMoreUrl
	 *  - the interface message MediaWiki:Cookiewarning-more-link
	 *  - the interface message MediaWiki:Cookie-policy-link (bc T145781)
	 *
	 * @return string|null The url or null if none set
	 * @throws ConfigException
	 */
	private static function getMoreLink() {
		$conf = self::getConfig();
		if ( $conf->get( 'CookieWarningMoreUrl' ) ) {
			return $conf->get( 'CookieWarningMoreUrl' );
		}

		$cookieWarningMessage = wfMessage( 'cookiewarning-more-link' );
		if ( $cookieWarningMessage->exists() && !$cookieWarningMessage->isDisabled() ) {
			return $cookieWarningMessage->text();
		}

		$cookiePolicyMessage = wfMessage( 'cookie-policy-link' );
		if ( $cookiePolicyMessage->exists() && !$cookiePolicyMessage->isDisabled() ) {
			return $cookiePolicyMessage->text();
		}

		return null;
	}

	/**
	 * BeforePageDisplay hook handler.
	 *
	 * Adds the required style and JS module, if cookiewarning is enabled.
	 *
	 * @param OutputPage $out
	 * @throws ConfigException
	 * @throws MWException
	 */
	public static function onBeforePageDisplay( OutputPage $out ) {
		/** @var Decisions $cookieWarningDecisions */
		$cookieWarningDecisions = MediaWikiServices::getInstance()
			->getService( 'CookieWarning.Decisions' );

		if ( !$cookieWarningDecisions->shouldShowCookieWarning( $out->getContext() ) ) {
			return;
		}

		if (
			ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' ) &&
			MobileContext::singleton()->shouldDisplayMobileView()
		) {
			$moduleStyles = [ 'ext.CookieWarning.mobile.styles' ];
		} else {
			$moduleStyles = [ 'ext.CookieWarning.styles' ];
		}
		$modules = [ 'ext.CookieWarning' ];

		if ( $cookieWarningDecisions->shouldAddResourceLoaderComponents() ) {
			$modules[] = 'ext.CookieWarning.geolocation';
			$moduleStyles[] = 'ext.CookieWarning.geolocation.styles';
		}
		$out->addModules( $modules );
		$out->addModuleStyles( $moduleStyles );
	}

	/**
	 * ResourceLoaderGetConfigVars hook handler.
	 *
	 * @param array &$vars
	 * @throws ConfigException
	 */
	public static function onResourceLoaderGetConfigVars( array &$vars ) {
		/** @var Decisions $cookieWarningDecisions */
		$cookieWarningDecisions = MediaWikiServices::getInstance()
			->getService( 'CookieWarning.Decisions' );
		$conf = self::getConfig();

		if ( $cookieWarningDecisions->shouldAddResourceLoaderComponents() ) {
			$vars += [
				'wgCookieWarningGeoIPServiceURL' => $conf->get( 'CookieWarningGeoIPServiceURL' ),
				'wgCookieWarningForCountryCodes' => $conf->get( 'CookieWarningForCountryCodes' ),
			];
		}
	}

	/**
	 * Returns the Config object for the CookieWarning extension.
	 *
	 * @return Config
	 */
	private static function getConfig() {
		return MediaWikiServices::getInstance()->getService( 'CookieWarning.Config' );
	}

	/**
	 * GetPreferences hook handler
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/GetPreferences
	 *
	 * @param User $user
	 * @param array &$defaultPreferences
	 * @return bool
	 */
	public static function onGetPreferences( User $user, &$defaultPreferences ) {
		$defaultPreferences['cookiewarning_dismissed'] = [
			'type' => 'api',
			'default' => '0',
		];
		return true;
	}
}
