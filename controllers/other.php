<?php

use GW2Spidy\DB\DisciplineQuery;

use GW2Spidy\DB\ItemSubTypeQuery;

use GW2Spidy\DB\ItemType;

use GW2Spidy\DB\RecipeQuery;

use GW2Spidy\Twig\GenericHelpersExtension;

use GW2Spidy\GW2SessionManager;

use \DateTime;

use GW2Spidy\DB\GW2Session;
use GW2Spidy\DB\GoldToGemRateQuery;
use GW2Spidy\DB\GemToGoldRateQuery;
use GW2Spidy\DB\ItemQuery;
use GW2Spidy\DB\ItemTypeQuery;
use GW2Spidy\DB\SellListingQuery;
use GW2Spidy\DB\WorkerQueueItemQuery;
use GW2Spidy\DB\ItemPeer;
use GW2Spidy\DB\BuyListingPeer;
use GW2Spidy\DB\SellListingPeer;
use GW2Spidy\DB\BuyListingQuery;

use GW2Spidy\Util\Functions;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;

use GW2Spidy\Application;

use GW2Spidy\Twig\VersionedAssetsRoutingExtension;
use GW2Spidy\Twig\ItemListRoutingExtension;
use GW2Spidy\Twig\GW2MoneyExtension;

use GW2Spidy\NewQueue\RequestSlotManager;
use GW2Spidy\NewQueue\QueueHelper;

/**
 * ----------------------
 *  route /
 * ----------------------
 */
$app->get("/", function() use($app) {
    // workaround for now to set active menu item
    $app->setHomeActive();

    $trendingUp = ItemQuery::create()
                        ->addDescendingOrderByColumn("sale_price_change_last_hour")
                        ->limit(3)
                        ->find();

    $trendingDown = ItemQuery::create()
                        ->addAscendingOrderByColumn("sale_price_change_last_hour")
                        ->limit(3)
                        ->find();


    $summary = gem_summary();

    return $app['twig']->render('index.html.twig', array(
        'trending_up' => $trendingUp,
        'trending_down' => $trendingDown,

    ) + (array)$summary);
})
->bind('homepage');

/**
 * ----------------------
 *  route /faq
 * ----------------------
 */
$app->get("/faq", function() use($app) {
    $app->setFAQActive();

    return $app['twig']->render('faq.html.twig');
})
->bind('faq');

/**
 * ----------------------
 *  route /status
 * ----------------------
 */
$app->get("/status/", function() use($app) {
    ob_start();

    echo "there are [[ " . RequestSlotManager::getInstance()->getLength() . " ]] available slots right now \n";
    echo "there are [[ " . QueueHelper::getInstance()->getItemListingDBQueueManager()->getLength() . " ]] items in the item listings queue \n";
    echo "there are [[ " . QueueHelper::getInstance()->getItemDBQueueManager()->getLength() . " ]] items in the item DB queue \n";

    $content = ob_get_clean();

    return $app['twig']->render('status.html.twig', array(
        'dump' => $content,
    ));
})
->bind('status');

/**
 * ----------------------
 *  route /admin/session
 * ----------------------
 */
$app->get("/admin/session", function(Request $request) use($app) {
    // workaround for now to set active menu item
    $app->setHomeActive();

    return $app['twig']->render('admin_session.html.twig', array(
        'flash'    => $request->get('flash'),
    ));
})
->bind('admin_session');

/**
 * ----------------------
 *  route /admin/session POST
 * ----------------------
 */
$app->post("/admin/session", function(Request $request) use($app) {
    $secret = trim($request->get('admin_secret', ''));
    if (!$app['debug'] && (!$secret || !getAppConfig('gw2spidy.admin_secret') || $secret !== getAppConfig('gw2spidy.admin_secret'))) {
        return '';
    }

    $session_key  = $request->get('session_key');
    $game_session = (boolean)$request->get('game_session');

    $gw2session = new GW2Session();
    $gw2session->setSessionKey($session_key);
    $gw2session->setGameSession($game_session);
    $gw2session->setCreated(new DateTime());

    try {
        try {
            $ok = GW2SessionManager::getInstance()->checkSessionAlive($gw2session);
        } catch (Exception $e) {
            $gw2session->save();
            return $app->redirect($app['url_generator']->generate('admin_session', array('flash' => "tpdown")));
        }

        if ($ok) {
            $gw2session->save();
            return $app->redirect($app['url_generator']->generate('admin_session', array('flash' => "ok")));
        } else {
            return $app->redirect($app['url_generator']->generate('admin_session', array('flash' => "dead")));
        }
    } catch (PropelException $e) {
        if (strstr($e->getMessage(), "Duplicate")) {
            return $app->redirect($app['url_generator']->generate('admin_session', array('flash' => "duplicate")));
        } else {
            throw $e;
        }
    }
})
->bind('admin_session_post');

/**
 * ----------------------
 *  route /profit
 * ----------------------
 */
$app->get("/profit", function(Request $request) use($app) {
    $where = "";

    if ($minlevel = intval($request->get('minlevel'))) {
        $where .= " AND (restriction_level = 0 OR restriction_level >= {$minlevel})";
    }

    $margin     = intval($request->get('margin')) ?: 500;
    $max_margin = intval($request->get('max_margin')) ?: 1000;

    if ($minprice = intval($request->get('minprice'))) {
        $where .= " AND min_sale_unit_price >= {$minprice}";
    }

    if ($maxprice = intval($request->get('maxprice'))) {
        $where .= " AND min_sale_unit_price <= {$maxprice}";
    }

    if ($type = intval($request->get('type'))) {
        $where .= " AND item_type_id = {$type}";
    }

    if ($blacklist = $request->get('blacklist')) {
        foreach (explode(",", $blacklist) as $blacklist) {
            $blacklist = Propel::getConnection()->quote("%{$blacklist}%", PDO::PARAM_STR);
            $where .= " AND name NOT LIKE {$blacklist}";
        }
    }

    $offset = intval($request->get('offset')) ?: 0;
    $limit  = intval($request->get('limit'))  ?: 50;
    $mmod   = intval($request->get('mmod')) ?: 0;
    $mnum   = intval($request->get('mnum')) ?: 0;

    $stmt = Propel::getConnection()->prepare("
    SELECT
        data_id,
        name,
        min_sale_unit_price,
        max_offer_unit_price,
        sale_availability,
        offer_availability,
        ((min_sale_unit_price*0.85 - max_offer_unit_price) / max_offer_unit_price) * 100 as margin
    FROM item
    WHERE offer_availability > 1
    AND   sale_availability > 5
    AND   max_offer_unit_price > 0
    AND   ((min_sale_unit_price*0.85 - max_offer_unit_price) / max_offer_unit_price) * 100 < {$max_margin}
    AND   (min_sale_unit_price*0.85 - max_offer_unit_price) > {$margin}
    {$where}
    ORDER BY margin DESC
    LIMIT {$offset}, {$limit}");

    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($mmod) {
        $i = 0;
        foreach ($data as $k => $v) {
            if ($i % $mmod != $mnum) {
                unset($data[$k]);
            }

            $i ++;
        }
    }

    if ($request->get('asJson')) {
        $json = array();

        foreach ($data as $row) {
            $json[] = $row['data_id'];
        }

        return json_encode($json);
    } else {
        return $app['twig']->render('quick_table.html.twig', array(
            'headers' => array_keys(reset($data)),
            'data'    => $data,
        ));
    }
});
