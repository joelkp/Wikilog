<?php

# Wikilog Calendar
# Календарь для расширения Wikilog
# Copyright (c) Vitaliy Filippov, 2010

class WikilogCalendar
{
    /* Weekday number (0-6) for the given UNIX time */
    static function weekday($ts)
    {
        global $wgWikilogWeekStart;
        if (!$wgWikilogWeekStart)
            $wgWikilogWeekStart = 0;
        return (date('N', $ts) + 6 - $wgWikilogWeekStart) % 7;
    }
    /* Next month */
    static function nextMonth($m)
    {
        if (0+substr($m, 4, 2) < 12)
            return substr($m, 0, 4) . sprintf("%02d", substr($m, 4, 2)+1);
        return (substr($m, 0, 4) + 1) . '01';
    }
    /* Previous month */
    static function prevMonth($m)
    {
        if (0+substr($m, 4, 2) > 1)
            return substr($m, 0, 4) . sprintf("%02d", substr($m, 4, 2)-1);
        return (substr($m, 0, 4) - 1) . '12';
    }
    /* Month and year name */
    static function monthName($month)
    {
        global $wgContLang;
        return $wgContLang->getMonthName(0+substr($month, 4, 2)).' '.substr($month, 0, 4);
    }
    /* Make HTML code for a multiple month calendar */
    static function makeCalendar($dates, $pager)
    {
        if (!$dates)
            return '';
        $months = array();
        foreach ($dates as $k => $d)
        {
            $m = substr($k, 0, 6);
            $months[$m] = true;
        }
        krsort($months);
        $months = array_keys($months);
        $html = '';
        foreach ($months as $m)
            $html .= self::makeMonthCalendar($m, $dates);
        /* append paging links */
        $links = self::makePagingLinks($months, $pager);
        $html = $links . $html . $links;
        return $html;
    }
    /* Make HTML code for paging links */
    static function makePagingLinks($months, $pager)
    {
        if ($pager->mIsFirst && $pager->mIsLast)
            return '';
        $urlLimit = $pager->mLimit == $pager->mDefaultLimit ? '' : $pager->mLimit;
        if ($pager->mIsFirst)
            $next = false;
        else
            $next = array('dir' => 'prev', 'offset' => ($nextmonth = self::nextMonth($months[0])).'01000000', 'limit' => $urlLimit);
        if ($pager->mIsLast)
            $prev = false;
        else
            $prev = array('dir' => 'next', 'offset' => ($prevmonth = $months[count($months)-1]).'01000000', 'limit' => $urlLimit );
        $html = '<p class="wl-calendar-nav">';
        if ($prev)
            $html .= $pager->makeLink(wfMsg('wikilog-calendar-prev', self::monthName(self::prevMonth($prevmonth))), $prev, 'prev');
        if ($next)
            $html .= $pager->makeLink(wfMsg('wikilog-calendar-next', self::monthName($nextmonth)), $next, 'next');
        $html .= '</p>';
        return $html;
    }
    /* Make HTML code for a single month calendar */
    static function makeMonthCalendar($month, $dates)
    {
        $max = self::nextMonth($month);
        $max = wfTimestamp(TS_UNIX, $max.'01000000')-86400;
        $max += 86400 * (6 - self::weekday($max));
        $min = wfTimestamp(TS_UNIX, $month.'01000000');
        $min -= 86400 * self::weekday($min);
        $html = '<table class="wl-calendar"><tr>';
        for ($ts = $min, $i = 0; $ts <= $max; $ts += 86400, $i++)
        {
            if ($i && !($i % 7))
                $html .= '</tr><tr>';
            $d = date('Ymd', $ts);
            $html .= '<td class="';
            if (substr($d, 0, 6) != $month)
                $html .= 'wl-calendar-other ';
            $html .= 'wl-calendar-day';
            if ($date = $dates[$d])
                $html .= '"><a href="'.htmlspecialchars($date['link']).'" title="'.htmlspecialchars($date['title']).'">';
            else
                $html .= '-empty">';
            $html .= date('j', $ts);
            if ($date)
                $html .= '</a>';
            $html .= '</td>';
        }
        $html .= '</tr></table>';
        $html = '<p class="wl-calendar-month">'.self::monthName($month).'</p>' . $html;
        return $html;
    }
    /* Make HTML code for calendar for the given fucking query object */
    static function sidebarCalendar($pager)
    {
        global $wgRequest, $wgWikilogNumArticles;
        list($limit) = $wgRequest->getLimitOffset($wgWikilogNumArticles, '');
        $offset = $wgRequest->getVal('offset');
        $dir = $wgRequest->getVal('dir') == 'prev';
        $dbr = wfGetDB(DB_SLAVE);
        $sql = $pager->mQuery->selectSQLText($dbr,
            array(), 'wikilog_posts.*',
            $offset ? array('wlp_pubdate' . ($dir ? '>' : '<') . $dbr->addQuotes($offset)) : array(),
            __FUNCTION__,
            array('LIMIT' => $limit, 'ORDER BY' => 'wlp_pubdate' . ($dir ? ' ASC' : ' DESC'))
        );
        $sql = "SELECT wlp_page, wlp_pubdate, COUNT(wlp_page) numposts FROM ($sql) derived GROUP BY SUBSTR(wlp_pubdate,1,8)";
        /* build date hash */
        $sp = Title::newFromText('Special:Wikilog');
        $dates = array();
        $res = $dbr->query($sql, __FUNCTION__);
        while ($row = $dbr->fetchRow($res))
        {
            $date = substr($row['wlp_pubdate'], 0, 8);
            if ($row['numposts'] == 1)
            {
                /* link to the post if it's the only one for that date */
                $title = Title::newFromId($row['wlp_page']);
                $dates[$date] = array(
                    'link'  => $title->getLocalUrl(),
                    'title' => $title->getPrefixedText(),
                );
            }
            else
            {
                /* link to archive page if there's more than one post for that date */
                $dates[$date] = array(
                    'link'  => $sp->getLocalUrl(array(
                        view  => 'archives',
                        year  => substr($date, 0, 4),
                        month => substr($date, 4, 2),
                        day   => substr($date, 6, 2),
                    )),
                    'title' => wfMsgExt('wikilog-calendar-archive-link-title', 'parseinline',
                        $sp->getPrefixedText(),
                        date('Y-m-d', wfTimestamp(TS_UNIX, $row['wlp_pubdate']))
                    ),
                );
            }
        }
        $dbr->freeResult($res);
        /* build calendar HTML code */
        $html = self::makeCalendar($dates, $pager);
        return $html;
    }
}