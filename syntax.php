<?php

use dokuwiki\HTTP\DokuHTTPClient;
use DOMWrap\Document;

/**
 * DokuWiki Plugin amazonlight (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */
class syntax_plugin_amazonlight extends DokuWiki_Syntax_Plugin
{

    /** @var array what regions to use for the different countries */
    const REGIONS = [
        'us' => 'www.amazon.com',
        'ca' => 'www.amazon.ca',
        'de' => 'www.amazon.de',
        'gb' => 'www.amazon.co.uk',
        'fr' => 'www.amazon.fr',
        'jp' => 'www.amazon.co.jp',
    ];

    protected DokuHTTPClient $http;

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


    public function __construct()
    {
        $http = new DokuHTTPClient();
        $http->headers['User-Agent'] = 'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';
        $http->headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
        $http->headers['Accept-Language'] = 'en-US,en;q=0.9';
        $http->headers['Upgrade-Insecure-Requests'] = '1';
        $http->headers['Sec-Ch-Ua'] = '"Google Chrome";v="119", "Chromium";v="119", "Not?A_Brand";v="24"';
        $http->headers['Sec-Ch-Ua-Mobile'] = '?0';
        $http->headers['Sec-Ch-Ua-Platform'] = '"Linux"';
        $http->headers['Sec-Fetch-Dest'] = 'document';
        $http->headers['Sec-Fetch-Mode'] = 'navigate';
        $http->headers['Sec-Fetch-Site'] = 'none';
        $http->headers['Sec-Fetch-User'] = '?1';
        $this->http = $http;
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = substr($match, 9, -2);
        list($ctry, $asin) = sexplode(':', $match, 2);

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
        list($asin, $more) = sexplode(' ', $asin, 2);
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
        } catch (Exception $e) {
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

        echo '<div class="amazon_author">';
        echo hsc($data['author']);
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

        // get homepage cookies first
        $this->http->get('https://' . $region);


        $url = 'https://' . $region . '/dp/' . $asin;

        $attempt = 0;
        $maxAttempts = 3;
        while ($attempt < $maxAttempts) {
            sleep($attempt);
            $attempt++;

            $html = $this->http->get($url);
            if (!$html) {
                if ($attempt < $maxAttempts) continue; // try a few times
                throw new Exception('Failed to fetch data. Status ' . $this->http->status);
            }
            if (preg_match('/(captcha|api-services-support@amazon.com)/i', $html)) {
                if ($attempt < $maxAttempts) continue; // try a few times
                throw new Exception('Anti-Bot mechanisms triggered, cannot fetch data');
            }
        }

        $doc = new Document();
        $doc->html($html);

        $result = [
            'title' => $this->extract($doc, '#productTitle'),
            'author' => $this->extract($doc, '#bylineInfo a'),
            'rating' => $this->extract($doc, '#averageCustomerReviews span.a-declarative a > span'),
            'price' => $this->extract($doc, '.priceToPay'),
            'isbn' => $this->extract($doc, '#rpi-attribute-book_details-isbn10 .rpi-attribute-value'),
            'img' => $this->extract($doc, '#imgTagWrapperId img', 'src'),
            'url' => $url . '?tag=' . $partner,
        ];

        if (!$result['title']) {
            $result['title'] = $this->extract($doc, 'title');
        }
        if (!$result['title']) {
            throw new Exception('Could not find title in data');
        }

        return $result;
    }

    /**
     * Extract text or attribute from a selector
     *
     * @param Document $doc
     * @param string $selector
     * @param string|null $attr attribute to extract, omit for text
     * @return string
     */
    protected function extract(Document $doc, string $selector, $attr = null): string
    {
        $element = $doc->find($selector)->first();
        if ($element === null) {
            return '';
        }
        if ($attr) {
            return $element->attr($attr);
        } else {
            return $element->text();
        }
    }
}

