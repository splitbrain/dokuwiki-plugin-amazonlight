<?php
/**
 * DokuWiki Plugin amazonlight (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class syntax_plugin_amazonlight extends DokuWiki_Syntax_Plugin
{

    /** @var array what regions to use for the different countries */
    const REGIONS = [
        'us' => 'ws-na',
        'ca' => 'ws-na',
        'de' => 'ws-eu',
        'gb' => 'ws-eu',
        'fr' => 'ws-eu',
        'jp' => 'ws-fe',
    ];

    /** @inheritDoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritDoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritDoc */
    public function getSort()
    {
        return 160;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('\{\{amazon>[\w:\\- =]+\}\}', $mode, 'plugin_amazonlight');
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = substr($match, 9, -2);
        list($ctry, $asin) = explode(':', $match, 2);

        // no country given?
        if (empty($asin)) {
            $asin = $ctry;
            $ctry = 'us';
        }

        // default parameters...
        $params = array(
            'imgw' => $this->getConf('imgw'),
            'imgh' => $this->getConf('imgh'),
            'price' => $this->getConf('showprice'),
        );
        // ...can be overridden
        list($asin, $more) = explode(' ', $asin, 2);
        $params['asin'] = $asin;

        if (preg_match('/(\d+)x(\d+)/i', $more, $match)) {
            $params['imgw'] = $match[1];
            $params['imgh'] = $match[2];
        }
        if (preg_match('/noprice/i', $more, $match)) {
            $params['price'] = false;
        } elseif (preg_match('/(show)?price/i', $more, $match)) {
            $params['price'] = true;
        }

        // correct country given?
        if ($ctry === 'uk') $ctry = 'gb';
        if (!preg_match('/^(us|gb|jp|de|fr|ca)$/', $ctry)) {
            $ctry = 'us';
        }
        $params['country'] = $ctry;

        return $params;
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode !== 'xhtml') {
            return false;
        }

        $html = $this->output($data);
        if (!$html) {
            if ($data['country'] == 'de') {
                $renderer->interwikilink('Amazon', 'Amazon.de', 'amazon.de', $data['asin']);
            } else {
                $renderer->interwikilink('Amazon', 'Amazon', 'amazon', $data['asin']);
            }
        }

        $renderer->doc .= $html;

        return true;
    }

    /**
     * @param array $param
     * @return string
     */
    protected function output($param)
    {
        global $conf;

        try {
            $data = $this->fetchData($param['asin'], $param['country']);
        } catch (\Exception $e) {
            msg(hsc($e->getMessage()), -1);
            return false;
        }

        $img = ml($data['img'], array('w' => $param['imgw'], 'h' => $param['imgh']));

        ob_start();
        echo '<div class="amazon">';
        echo '<a href="' . $data['url'] . '"';
        if ($conf['target']['extern']) echo ' target="' . $conf['target']['extern'] . '"';
        echo '>';
        echo '<img src="' . $img . '" width="' . $param['imgw'] . '" height="' . $param['imgh'] . '" alt="" />';
        echo '</a>';

        echo '<div class="amazon_title">';
        echo '<a href="' . $data['url'] . '"';
        if ($conf['target']['extern']) echo ' target="' . $conf['target']['extern'] . '"';
        echo '>';
        echo hsc($data['title']);
        echo '</a>';
        echo '</div>';

        echo '<div class="amazon_isbn">';
        echo hsc($data['isbn']);
        echo '</div>';

        if ($param['price'] && $data['price']) {
            echo '<div class="amazon_price">' . hsc($data['price']) . '</div>';
        }
        echo '</div>';

        return ob_get_clean();
    }

    /**
     * Fetch the meta data
     *
     * @param string $asin
     * @param string $country
     * @return array
     * @throws Exception
     */
    protected function fetchData($asin, $country)
    {
        $partner = $this->getConf('partner_' . $country);
        if (!$partner) $partner = 'none';
        $region = self::REGIONS[$country];

        $attr = [
            'ServiceVersion' => '20070822',
            'OneJS' => '1',
            'Operation' => 'GetAdHtml',
            'MarketPlace' => strtoupper($country),
            'source' => 'ss',
            'ref' => 'as_ss_li_til',
            'ad_type' => 'product_link',
            'tracking_id' => $partner,
            'marketplace' => 'amazon',
            'region' => strtoupper($country),
            'placement' => '0670022411',
            'asins' => $asin,
            'show_border' => 'true',
            'link_opens_in_new_window' => 'true',
        ];
        $url = 'http://' . $region . '.amazon-adsystem.com/widgets/q?' . buildURLparams($attr, '&');

        $http = new DokuHTTPClient();
        $html = $http->get($url);
        if (!$html) {
            throw new \Exception('Failed to fetch data. Status ' . $http->status);
        }

        $result = [];

        if (preg_match('/class="price".*?>(.*?)<\/span>/s', $html, $m)) {
            $result['price'] = $m[1];
        }

        if (preg_match('/<a .* id="titlehref" [^>]*?>([^<]*?)<\/a>/s', $html, $m)) {
            $result['title'] = $m[1];
        } else {
            throw new \Exception('Could not find title in data');
        }

        if (preg_match('/<a .* id="titlehref" href=(.*?) /s', $html, $m)) {
            $result['url'] = trim($m[1], '\'"');
        } else {
            throw new \Exception('Could not find url in data');
        }

        if (preg_match('/^\d{10,13}$/', $asin)) {
            $result['isbn'] = 'ISBN ' . $asin;
        }

        $result['img'] = $this->getImageURL($asin, $country);

        return $result;
    }

    /**
     * @param $asin
     * @param $country
     * @return string
     */
    protected function getImageURL($asin, $country)
    {
        $partner = $this->getConf('partner_' . $country);
        if (!$partner) $partner = 'none';
        $region = self::REGIONS[$country];

        $attr = [
            '_encoding' => 'UTF8',
            'ASIN' => $asin,
            'Format' => '_SL250_',
            'ID' => 'AsinImage',
            'MarketPlace' => strtoupper($country),
            'ServiceVersion' => '20070822',
            'WS' => '1',
            'tag' => $partner,
        ];
        $url = 'https://' . $region . '.amazon-adsystem.com/widgets/q?' . buildURLparams($attr, '&');

        return $url;
    }

}

