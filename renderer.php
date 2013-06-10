<?php
/**
 * Renderer for media files.
 *
 * Used in file resources, media filter, and any other places that need to
 * output embedded media.
 *
 * @copyright 2011 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_html5embed_media_renderer extends plugin_renderer_base {
    /** @var array Array of available 'player' objects */
    private $players;
    /** @var string Regex pattern for links which may contain embeddable content */
    private $embeddablemarkers;

    /**
     * Constructor requires medialib.php.
     *
     * This is needed in the constructor (not later) so that you can use the
     * constants and static functions that are defined in html5embed_media class
     * before you call renderer functions.
     */
    public function __construct() {
        global $CFG;
        require_once($CFG->libdir . '/../filter/html5embed/medialib.php');
    }

    /**
     * Obtains the list of html5embed_media_player objects currently in use to render
     * items.
     *
     * The list is in rank order (highest first) and does not include players
     * which are disabled.
     *
     * @return array Array of html5embed_media_player objects in rank order
     */
    protected function get_players() {
        global $CFG;

        // Save time by only building the list once.
        if (!$this->players) {
            // Get raw list of players.
            $players = $this->get_players_raw();

            // Chuck all the ones that are disabled.
            foreach ($players as $key => $player) {
                if (!$player->is_enabled()) {
                    unset($players[$key]);
                }
            }

            // Sort in rank order (highest first).
            usort($players, array('html5embed_media_player', 'compare_by_rank'));
            $this->players = $players;
        }
        return $this->players;
    }

    /**
     * Obtains a raw list of player objects that includes objects regardless
     * of whether they are disabled or not, and without sorting.
     *
     * You can override this in a subclass if you need to add additional
     * players.
     *
     * The return array is be indexed by player name to make it easier to
     * remove players in a subclass.
     *
     * @return array $players Array of html5embed_media_player objects in any order
     */
    protected function get_players_raw() {
        return array(
            'html5video' => new html5embed_media_player_html5video(),
            'html5audio' => new html5embed_media_player_html5audio()
        );
    }

    /**
     * Renders a media file (audio or video) using suitable embedded player.
     *
     * See embed_alternatives function for full description of parameters.
     * This function calls through to that one.
     *
     * When using this function you can also specify width and height in the
     * URL by including ?d=100x100 at the end. If specified in the URL, this
     * will override the $width and $height parameters.
     *
     * @param moodle_url $url Full URL of media file
     * @param string $name Optional user-readable name to display in download link
     * @param int $width Width in pixels (optional)
     * @param int $height Height in pixels (optional)
     * @param array $options Array of key/value pairs
     * @return string HTML content of embed
     */
    public function embed_url(moodle_url $url, $name = '', $width = 0, $height = 0,
            $options = array()) {

        // Get width and height from URL if specified (overrides parameters in
        // function call).
        $rawurl = $url->out(false);
        if (preg_match('/[?#]d=([\d]{1,4}%?)x([\d]{1,4}%?)/', $rawurl, $matches)) {
            $width = $matches[1];
            $height = $matches[2];
            $url = new moodle_url(str_replace($matches[0], '', $rawurl));
        }

        // Defer to array version of function.
        return $this->embed_alternatives(array($url), $name, $width, $height, $options);
    }

    /**
     * Renders media files (audio or video) using suitable embedded player.
     * The list of URLs should be alternative versions of the same content in
     * multiple formats. If there is only one format it should have a single
     * entry.
     *
     * If the media files are not in a supported format, this will give students
     * a download link to each format. The download link uses the filename
     * unless you supply the optional name parameter.
     *
     * Width and height are optional. If specified, these are suggested sizes
     * and should be the exact values supplied by the user, if they come from
     * user input. These will be treated as relating to the size of the video
     * content, not including any player control bar.
     *
     * For audio files, height will be ignored. For video files, a few formats
     * work if you specify only width, but in general if you specify width
     * you must specify height as well.
     *
     * The $options array is passed through to the html5embed_media_player classes
     * that render the object tag. The keys can contain values from
     * html5embed_media::OPTION_xx.
     *
     * @param array $alternatives Array of moodle_url to media files
     * @param string $name Optional user-readable name to display in download link
     * @param int $width Width in pixels (optional)
     * @param int $height Height in pixels (optional)
     * @param array $options Array of key/value pairs
     * @return string HTML content of embed
     */
    public function embed_alternatives($alternatives, $name = '', $width = 0, $height = 0,
            $options = array()) {

        // Get list of player plugins (will also require the library).
        $players = $this->get_players();

        // Set up initial text which will be replaced by first player that
        // supports any of the formats.
        $out = html5embed_media_player::PLACEHOLDER;

        // Loop through all players that support any of these URLs.
        foreach ($players as $player) {
            // Option: When no other player matched, don't do the default link player.
            if (!empty($options[html5embed_media::OPTION_FALLBACK_TO_BLANK]) &&
                    $player->get_rank() === 0 && $out === html5embed_media_player::PLACEHOLDER) {
                continue;
            }

            $supported = $player->list_supported_urls($alternatives, $options);
            if ($supported) {
                // Embed.
                $text = $player->embed($supported, $name, $width, $height, $options);

                // Put this in place of the 'fallback' slot in the previous text.
                $out = str_replace(html5embed_media_player::PLACEHOLDER, $text, $out);
            }
        }

        // Remove 'fallback' slot from final version and return it.
        $out = str_replace(html5embed_media_player::PLACEHOLDER, '', $out);
        if (!empty($options[html5embed_media::OPTION_BLOCK]) && $out !== '') {
            $out = html_writer::tag('div', $out, array('class' => 'resourcecontent'));
        }
        return $out;
    }

    /**
     * Checks whether a file can be embedded. If this returns true you will get
     * an embedded player; if this returns false, you will just get a download
     * link.
     *
     * This is a wrapper for can_embed_urls.
     *
     * @param moodle_url $url URL of media file
     * @param array $options Options (same as when embedding)
     * @return bool True if file can be embedded
     */
    public function can_embed_url(moodle_url $url, $options = array()) {
        return $this->can_embed_urls(array($url), $options);
    }

    /**
     * Checks whether a file can be embedded. If this returns true you will get
     * an embedded player; if this returns false, you will just get a download
     * link.
     *
     * @param array $urls URL of media file and any alternatives (moodle_url)
     * @param array $options Options (same as when embedding)
     * @return bool True if file can be embedded
     */
    public function can_embed_urls(array $urls, $options = array()) {
        // Check all players to see if any of them support it.
        foreach ($this->get_players() as $player) {
            // Link player (always last on list) doesn't count!
            if ($player->get_rank() <= 0) {
                break;
            }
            // First player that supports it, return true.
            if ($player->list_supported_urls($urls, $options)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Obtains a list of markers that can be used in a regular expression when
     * searching for URLs that can be embedded by any player type.
     *
     * This string is used to improve peformance of regex matching by ensuring
     * that the (presumably C) regex code can do a quick keyword check on the
     * URL part of a link to see if it matches one of these, rather than having
     * to go into PHP code for every single link to see if it can be embedded.
     *
     * @return string String suitable for use in regex such as '(\.mp4|\.flv)'
     */
    public function get_embeddable_markers() {
        if (empty($this->embeddablemarkers)) {
            $markers = '';
            foreach ($this->get_players() as $player) {
                foreach ($player->get_embeddable_markers() as $marker) {
                    if ($markers !== '') {
                        $markers .= '|';
                    }
                    $markers .= preg_quote($marker);
                }
            }
            $this->embeddablemarkers = $markers;
        }
        return $this->embeddablemarkers;
    }
}
