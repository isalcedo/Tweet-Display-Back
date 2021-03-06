<?php
/**
 * Tweet Display Back Module for Joomla!
 *
 * @package    TweetDisplayBack
 *
 * @copyright  Copyright (C) 2010-2012 Michael Babker. All rights reserved.
 * @license    GNU/GPL - http://www.gnu.org/copyleft/gpl.html
 */

defined('_JEXEC') or die;

/**
 * Helper class for Tweet Display Back
 *
 * @package  TweetDisplayBack
 * @since    1.0
 */
class ModTweetDisplayBackHelper
{
	/**
	 * Function to compile the data to render a formatted object displaying a Twitter feed
	 *
	 * @param   JRegistry  $params  The module parameters
	 *
	 * @return  object  An object with the formatted tweets
	 *
	 * @since   1.5
	 */
	public static function compileData($params)
	{
		// Load the parameters
		$uname   = $params->get('twitterName', '');
		$list    = $params->get('twitterList', '');
		$count   = $params->get('twitterCount', 3);
		$retweet = $params->get('tweetRetweets', 1);
		$feed    = $params->get('twitterFeedType', 'user');

		// Convert the list name to a usable string for the JSON
		if ($list)
		{
			$flist = self::toAscii($list);
		}

		// Get the user info
		$twitter = self::prepareUser($params);

		// Check to see if we have an error or are out of hits
		if (isset($twitter->error) || isset($twitter->hits))
		{
			return $twitter;
		}

		// Set the include RT's string
		$incRT = '';

		if ($retweet == 1)
		{
			$incRT = '&include_rts=1';
		}

		// Count the number of active filters
		$activeFilters = 0;

		// Mentions
		if ($params->get('showMentions', 0) == 0)
		{
			$activeFilters++;
		}

		// Replies
		if ($params->get('showReplies', 0) == 0)
		{
			$activeFilters++;
		}

		// Retweets
		if ($retweet == 0)
		{
			$activeFilters++;
		}

		// Determine whether the feed being returned is a user, favorites, or list feed
		if ($feed == 'list')
		{
			// Get the list feed
			$req = 'http://api.twitter.com/1/lists/statuses.json?slug=' . $flist . '&owner_screen_name=' . $uname . $incRT . '&include_entities=1';
		}
		elseif ($feed == 'favorites')
		{
			// Get the favorites feed
			$req = 'http://api.twitter.com/1/favorites.json?count=' . $count . '&screen_name=' . $uname . '&include_entities=1';
		}
		else
		{
			/*
			 * Get the user feed, we have to manually filter mentions and replies,
			 * & Twitter doesn't send additional tweets when RTs are not included
			 * So get additional tweets by multiplying $count based on the number
			 * of active filters
			 */
			if ($activeFilters == 1)
			{
				$count = $count * 3;
			}
			elseif ($activeFilters == 2)
			{
				$count = $count * 4;
			}
			elseif ($activeFilters == 3)
			{
				$count = $count * 5;
			}
			/*
			 * Determine whether the user has overridden the count parameter with a
			 * manual number of tweets to retrieve.  Override the $count variable
			 * if this is the case
			 */
			if ($params->get('overrideCount', 1) == 1)
			{
				$count = $params->get('tweetsToScan', 3);
			}
			$req = 'http://api.twitter.com/1/statuses/user_timeline.json?count=' . $count . '&screen_name=' . $uname . $incRT . '&include_entities=1';
		}

		// Fetch the decoded JSON
		$obj = self::getJSON($req);

		// Check if we've exceeded the rate limit
		if (isset($obj['error']) && $obj['error'] == 'Rate limit exceeded. Clients may not make more than 150 requests per hour.')
		{
			$twitter->hits = '';
		}
		// Make sure we've got an array of data
		elseif (is_array($obj))
		{
			// Process the filtering options and render the feed
			$twitter->tweet = self::processFiltering($obj, $params);
		}
		else
		{
			$twitter->error = '';
		}

		return $twitter;
	}

	/**
	 * Function to fetch a JSON feed
	 *
	 * @param   string  $req  The URL of the feed to load
	 *
	 * @return  object  The fetched JSON query
	 *
	 * @since   1.0
	 */
	public static function getJSON($req)
	{
		static $http;

		// Instantiate our JHttp connector if not already
		if ($http == null)
		{
			// Set up our JRegistry object for the HTTP connector
			$options = new JRegistry;

			// Set the user agent
			$options->set('userAgent', 'TweetDisplayBack/2.2');

			// Use a 30 second timeout
			$options->set('timeout', 30);

			// Instantiate our JHttp object
			$http = new JHttp($options);
		}

		// Get the data
		try
		{
			$response = $http->get($req);
		}
		catch (UnexpectedValueException $e)
		{
			return null;
		}

		// Return the decoded response body
		return json_decode($response->body);
	}

	/**
	 * Function to fetch the user JSON and render it
	 *
	 * @param   JRegistry  $params  The module parameters
	 *
	 * @return  array  The formatted object for display
	 *
	 * @since   1.5
	 */
	protected static function prepareUser($params)
	{
		// Load the parameters
		$uname = $params->get('twitterName', '');
		$list  = $params->get('twitterList', '');
		$feed  = $params->get('twitterFeedType', 'user');

		// Initialize new object containers
		$twitter = new stdClass;
		$twitter->header = new stdClass;
		$twitter->footer = new stdClass;

		// Convert the list name to a usable string for the URL
		if ($list)
		{
			$flist = self::toAscii($list);
		}

		// Get the user JSON
		$req = 'http://api.twitter.com/1/users/show.json?screen_name=' . $uname;

		// Decode the fetched JSON
		$obj = self::getJSON($req);

		// Check if we've exceeded the rate limit
		if (isset($obj->error) && $obj->error == 'Rate limit exceeded. Clients may not make more than 150 requests per hour.')
		{
			$twitter->hits = '';

			return $twitter;
		}
		// Check that we have the JSON, otherwise set an error
		elseif (!$obj)
		{
			$twitter->error = '';

			return $twitter;
		}

		/*
		 * Header info
		 */

		if ($params->get('headerUser', 1) == 1)
		{
			// Check if the Intents action is bypassed
			if ($params->get('bypassIntent', '0') == 1)
			{
				$twitter->header->user = '<a href="http://twitter.com/' . $uname . '" rel="nofollow" target="_blank">';
			}
			else
			{
				$twitter->header->user = '<a href="http://twitter.com/intent/user?screen_name=' . $uname . '" rel="nofollow">';
			}

			// Show the real name or the username
			if ($params->get('headerName', 1) == 1)
			{
				$twitter->header->user .= $obj->name . '</a>';
			}
			else
			{
				$twitter->header->user .= $uname . '</a>';
			}
			// Append the list name if being pulled
			if ($feed == 'list')
			{
				$twitter->header->user .= ' - <a href="http://twitter.com/' . $uname . '/' . $flist . '" rel="nofollow">' . $list . ' list</a>';
			}
		}
		// Show the bio
		if ($params->get('headerBio', 1) == 1)
		{
			$twitter->header->bio = $obj->description;
		}
		// Show the location
		if ($params->get('headerLocation', 1) == 1)
		{
			$twitter->header->location = $obj->location;
		}
		// Show the user's URL
		if ($params->get('headerWeb', 1) == 1)
		{
			$twitter->header->web = '<a href="' . $obj->url . '" rel="nofollow" target="_blank">' . $obj->url . '</a>';
		}

		// Get the profile image URL from the object
		$avatar = $obj->profile_image_url;

		// Switch from the normal size avatar (48px) to the large one (73px)
		$avatar = str_replace('normal.jpg', 'bigger.jpg', $avatar);

		$twitter->header->avatar = '<img src="' . $avatar . '" alt="' . $uname . '" />';

		/*
		 * Footer info
		 */

		// Display the Follow button
		if ($params->get('footerFollowLink', 1) == 1)
		{
			// Don't display for a list feed
			if ($feed != 'list')
			{
				$followParams  = 'screen_name=' . $uname;
				$followParams .= '&lang=' . substr(JFactory::getLanguage()->getTag(), 0, 2);
				$followParams .= '&show_count=' . (bool) $params->get('footerFollowCount', 1);
				$followParams .= '&show_screen_name=' . (bool) $params->get('footerFollowUser', 1);

				$iframe = '<iframe allowtransparency="true" frameborder="0" scrolling="no" src="http://platform.twitter.com/widgets/follow_button.html?' . $followParams . '" style="width: 300px; height: 20px;"></iframe>';

				$twitter->footer->follow_me = '<div class="TDB-footer-follow-link">' . $iframe . '</div>';
			}
		}
		if ($params->get('footerPoweredBy', 1) == 1)
		{
			$site = '<a href="http://www.babdev.com/extensions/tweet-display-back" rel="nofollow" target="_blank">' . JText::_('MOD_TWEETDISPLAYBACK') . '</a>';
			$twitter->footer->powered_by = '<hr /><div class="TDB-footer-powered-text">' . JText::sprintf('MOD_TWEETDISPLAYBACK_POWERED_BY', $site) . '</div>';
		}

		return $twitter;
	}

	/**
	 * Function to render the Twitter feed into a formatted object
	 *
	 * @param   object     $obj     The decoded JSON feed
	 * @param   JRegistry  $params  The module parameters
	 *
	 * @return  object  The formatted object for display
	 *
	 * @since   2.0
	 */
	protected static function processFiltering($obj, $params)
	{
		// Initialize
		$count          = $params->get('twitterCount', 3);
		$showMentions   = $params->get('showMentions', 0);
		$showReplies    = $params->get('showReplies', 0);
		$numberOfTweets = $params->get('twitterCount', 3);
		$feedType       = $params->get('twitterFeedType', 'user');
		$twitter        = array();
		$i = 0;

		// Check if $obj has data; if not, return an error
		if (is_null($obj))
		{
			// Set an error
			$twitter[$i]->tweet->text = JText::_('MOD_TWEETDISPLAYBACK_ERROR_UNABLETOLOAD');
		}
		else
		{
			// Process the feed
			foreach ($obj as $o)
			{
				if ($count > 0)
				{
					// Check if we have all of the items we want
					if ($i < $numberOfTweets)
					{
						// If we aren't filtering, just render the item
						if (($showMentions == 1 && $showReplies == 1) || ($feedType == 'list' || $feedType == 'favorites'))
						{
							self::processItem($twitter, $o, $i, $params);

							// Modify counts
							$count--;
							$i++;
						}

						// We're filtering, the fun starts here
						else
						{
							// Set variables
							// Tweets which contains a @reply
							$tweetContainsReply = $o->in_reply_to_user_id != null;

							// Tweets which contains a @mention and/or @reply
							$tweetContainsMentionAndOrReply = $o->entities->user_mentions != null;

							/*
							 * Check if a reply tweet contains mentions
							 * NOTE: Works only for tweets where there is also a reply, since reply is at
							 * the position ['0'] and mentions begin at ['1'].
							 */
							if (isset($o->entities->user_mentions['1']))
							{
								$replyTweetContainsMention = $o->entities->user_mentions['1'];
							}
							else
							{
								$replyTweetContainsMention = '0';
							}

							// Tweets with only @reply
							$tweetOnlyReply = $tweetContainsReply && $replyTweetContainsMention == '0';

							// Tweets which contains @mentions or @mentions+@reply
							$tweetContainsMention = $tweetContainsMentionAndOrReply && !$tweetOnlyReply;

							// Filter @mentions and @replies, leaving retweets unchanged
							if ($showMentions == 0 && $showReplies == 0)
							{
								if (!$tweetContainsMentionAndOrReply || isset($o->retweeted_status))
								{
									self::processItem($twitter, $o, $i, $params);

									// Modify counts
									$count--;
									$i++;
								}
							}

							// Filtering only @mentions or @replies
							else
							{
								// Filter @mentions only leaving @replies and retweets unchanged
								if ($showMentions == 0)
								{
									if (!$tweetContainsMention || isset($o->retweeted_status))
									{
										self::processItem($twitter, $o, $i, $params);

										// Modify counts
										$count--;
										$i++;
									}
								}

								// Filter @replies only (including @replies with @mentions) leaving retweets unchanged
								if ($showReplies == 0)
								{
									if (!$tweetContainsReply)
									{
										self::processItem($twitter, $o, $i, $params);

										// Modify counts
										$count--;
										$i++;
									}
								}

								// Somehow, we got this far; process the tweet
								if ($showMentions == 1 && $showReplies == 1)
								{
									// No filtering required
									self::processItem($twitter, $o, $i, $params);

									// Modify counts
									$count--;
									$i++;
								}
							}
						}
					}
				}
			}
		}

		return $twitter;
	}

	/**
	 * Function to process the Twitter feed into a formatted object
	 *
	 * @param   array      &$twitter  The output array
	 * @param   object     $o         The item within the JSON feed
	 * @param   integer    $i         Iteration of processFiltering
	 * @param   JRegistry  $params    The module parameters
	 *
	 * @return  void
	 *
	 * @since   2.0
	 */
	protected static function processItem(&$twitter, $o, $i, $params)
	{
		// Set variables
		$tweetName    = $params->get('tweetName', 1);
		$tweetReply   = $params->get('tweetReply', 1);
		$tweetRTCount = $params->get('tweetRetweetCount', 1);

		// Initialize a new object
		$twitter[$i]        = new stdClass;
		$twitter[$i]->tweet = new stdClass;

		// Check if the item is a retweet, and if so gather data from the retweeted_status datapoint
		if (isset($o->retweeted_status))
		{
			// Retweeted user
			$tweetedBy = $o->retweeted_status->user->screen_name;
			$avatar = $o->retweeted_status->user->profile_image_url;
			$text = $o->retweeted_status->text;
			$urls = $o->retweeted_status->entities->urls;
			$RTs = $o->retweeted_status->retweet_count;
			$twitter[$i]->tweet->created = JText::_('MOD_TWEETDISPLAYBACK_RETWEETED');

			if (isset($o->retweeted_status->entities->media))
			{
				$media = $o->retweeted_status->entities->media;
			}
		}
		else
		{
			// User
			$tweetedBy = $o->user->screen_name;
			$avatar = $o->user->profile_image_url;
			$text = $o->text;
			$urls = $o->entities->urls;
			$RTs = $o->retweet_count;

			if (isset($o->entities->media))
			{
				$media = $o->entities->media;
			}
		}
		// Generate the object with the user data
		if ($tweetName == 1)
		{
			// Check if the Intents action is bypassed
			if ($params->get('bypassIntent', '0') == 1)
			{
				$userURL = 'http://twitter.com/' . $tweetedBy;
			}
			else
			{
				$userURL = 'http://twitter.com/intent/user?screen_name=' . $tweetedBy;
			}
			$twitter[$i]->tweet->user = '<b><a href="' . $userURL . '" rel="nofollow">' . $tweetedBy . '</a>' . $params->get('tweetUserSeparator') . '</b>';
		}
		$twitter[$i]->tweet->avatar = '<img alt="' . $tweetedBy . '" src="' . $avatar . '" width="32" />';
		$twitter[$i]->tweet->text = $text;

		// Make regular URLs in tweets a link
		foreach ($urls as $url)
		{
			if (isset($url->display_url))
			{
				$d_url = $url->display_url;
			}
			else
			{
				$d_url = $url->url;
			}

			// We need to check to verify that the URL has the protocol, just in case
			if (strpos($url->url, 'http') !== 0)
			{
				// Prepend http since there's no protocol
				$link = 'http://' . $url->url;
			}
			else
			{
				$link = $url->url;
			}

			$twitter[$i]->tweet->text = str_replace($url->url, '<a href="' . $link . '" target="_blank" rel="nofollow">' . $d_url . '</a>', $twitter[$i]->tweet->text);
		}

		// Make media URLs in tweets a link
		if (isset($media))
		{
			foreach ($media as $image)
			{
				if (isset($image->display_url))
				{
					$i_url = $image->display_url;
				}
				else
				{
					$i_url = $image->url;
				}

				$twitter[$i]->tweet->text = str_replace($image->url, '<a href="' . $image->url . '" target="_blank" rel="nofollow">' . $i_url . '</a>', $twitter[$i]->tweet->text);
			}
		}

		/*
		 * Info below is specific to each tweet, so it isn't checked against a retweet
		 */

		// Display the time the tweet was created
		if ($params->get('tweetCreated', 1) == 1)
		{
			$twitter[$i]->tweet->created .= '<a href="http://twitter.com/' . $o->user->screen_name . '/status/' . $o->id_str . '" rel="nofollow" target="_blank">';

			// Determine whether to display the time as a relative or static time
			if ($params->get('tweetRelativeTime', 1) == 1)
			{
				$time = JFactory::getDate($o->created_at, 'UTC');
				$twitter[$i]->tweet->created .= JHtml::_('date.relative', $time, null, JFactory::getDate('now', 'UTC')) . '</a>';
			}
			else
			{
				$twitter[$i]->tweet->created .= JHtml::date($o->created_at) . '</a>';
			}
		}
		// Display the tweet source
		if (($params->get('tweetSource', 1) == 1))
		{
			$twitter[$i]->tweet->created .= JText::sprintf('MOD_TWEETDISPLAYBACK_VIA', $o->source);
		}

		// Display the location the tweet was made from if set
		if (($params->get('tweetLocation', 1) == 1) && (isset($o->place->full_name)))
		{
			$twitter[$i]->tweet->created .= JText::_('MOD_TWEETDISPLAYBACK_FROM') . '<a href="http://maps.google.com/maps?q=' . $o->place->full_name . '" target="_blank" rel="nofollow">' . $o->place->full_name . '</a>';
		}

		// If the tweet is a reply, display a link to the tweet it's in reply to
		if ((($o->in_reply_to_screen_name) && ($o->in_reply_to_status_id_str)) && $params->get('tweetReplyLink', 1) == 1)
		{
			$twitter[$i]->tweet->created .= JText::_('MOD_TWEETDISPLAYBACK_IN_REPLY_TO') . '<a href="http://twitter.com/' . $o->in_reply_to_screen_name . '/status/' . $o->in_reply_to_status_id_str . '" rel="nofollow">' . $o->in_reply_to_screen_name . '</a>';
		}

		// Display the number of times the tweet has been retweeted
		if ((($tweetRTCount == 1) && ($RTs >= 1)))
		{
			$twitter[$i]->tweet->created .= ' &bull; ' . JText::plural('MOD_TWEETDISPLAYBACK_RETWEETS', $RTs);
		}

		// Display Twitter Actions
		if ($tweetReply == 1)
		{
			$twitter[$i]->tweet->actions = '<span class="TDB-action TDB-reply"><a href="http://twitter.com/intent/tweet?in_reply_to=' . $o->id_str . '" title="' . JText::_('MOD_TWEETDISPLAYBACK_INTENT_REPLY') . '" rel="nofollow"></a></span>';
			$twitter[$i]->tweet->actions .= '<span class="TDB-action TDB-retweet"><a href="http://twitter.com/intent/retweet?tweet_id=' . $o->id_str . '" title="' . JText::_('MOD_TWEETDISPLAYBACK_INTENT_RETWEET') . '" rel="nofollow"></a></span>';
			$twitter[$i]->tweet->actions .= '<span class="TDB-action TDB-favorite"><a href="http://twitter.com/intent/favorite?tweet_id=' . $o->id_str . '" title="' . JText::_('MOD_TWEETDISPLAYBACK_INTENT_FAVORITE') . '" rel="nofollow"></a></span>';
		}

		// If set, convert user and hash tags into links
		if ($params->get('tweetLinks', 1) == 1)
		{
			foreach ($o->entities->user_mentions as $mention)
			{
				// Check if the Intents action is bypassed
				if ($params->get('bypassIntent', '0') == 1)
				{
					$mentionURL = 'http://twitter.com/' . $mention->screen_name;
				}
				else
				{
					$mentionURL = 'http://twitter.com/intent/user?screen_name=' . $mention->screen_name;
				}
				$twitter[$i]->tweet->text = str_ireplace('@' . $mention->screen_name, '@<a class="userlink" href="' . $mentionURL . '" rel="nofollow">' . $mention->screen_name . '</a>', $twitter[$i]->tweet->text);
			}
			foreach ($o->entities->hashtags as $hashtag)
			{
				$twitter[$i]->tweet->text = str_ireplace('#' . $hashtag->text, '#<a class="hashlink" href="http://twitter.com/search?q=' . $hashtag->text . '" target="_blank" rel="nofollow">' . $hashtag->text . '</a>', $twitter[$i]->tweet->text);
			}
		}
	}

	/**
	 * Function to convert a formatted list name into it's URL equivalent
	 *
	 * @param   string  $list  The user inputted list name
	 *
	 * @return  string  The list name converted
	 *
	 * @since   1.6
	 */
	public static function toAscii($list)
	{
		$clean = preg_replace("/[^a-z'A-Z0-9\/_|+ -]/", '', $list);
		$clean = strtolower(trim($clean, '-'));
		$list  = preg_replace("/[\/_|+ -']+/", '-', $clean);

		return $list;
	}
}
