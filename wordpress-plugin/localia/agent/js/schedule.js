/*
 * Schedule rules: the agent only polls while at least one rule is active.
 * Rule: { enabled, days:[0..6] (0 = Sunday), start:"HH:MM", end:"HH:MM", from:"YYYY-MM-DD"|"" , to:"YYYY-MM-DD"|"" }
 *  - start == end  -> all day on the selected days
 *  - start >  end  -> overnight window (e.g. 22:00-07:00 runs into the next morning)
 *  - from / to     -> optional date range (inclusive) the rule applies to
 */
(function (global) {
  'use strict';

  const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  const toMin = (hhmm) => {
    const m = /^(\d{1,2}):(\d{2})$/.exec(hhmm || '');
    return m ? Math.min(23, +m[1]) * 60 + Math.min(59, +m[2]) : 0;
  };

  const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

  // Is the rule "on" for a window that STARTS on calendar day `d`?
  function dayOk(rule, d) {
    if (!rule.days || !rule.days.includes(d.getDay())) return false;
    const day = ymd(d);
    if (rule.from && day < rule.from) return false;
    if (rule.to && day > rule.to) return false;
    return true;
  }

  function ruleActive(rule, now) {
    if (!rule || rule.enabled === false) return false;
    const mins = now.getHours() * 60 + now.getMinutes();
    const start = toMin(rule.start);
    const end = toMin(rule.end);
    if (start === end) return dayOk(rule, now);
    if (start < end) return dayOk(rule, now) && mins >= start && mins < end;
    // overnight
    if (mins >= start) return dayOk(rule, now);
    if (mins < end) {
      const y = new Date(now);
      y.setDate(y.getDate() - 1);
      return dayOk(rule, y);
    }
    return false;
  }

  function isActive(settings, now = new Date()) {
    if (settings.scheduleMode !== 'schedule') return true;
    return (settings.scheduleRules || []).some((r) => ruleActive(r, now));
  }

  /** Next time the active/inactive state flips (minute resolution, searches 14 days ahead). */
  function nextChange(settings, now = new Date()) {
    if (settings.scheduleMode !== 'schedule') return null;
    const current = isActive(settings, now);
    const t = new Date(now);
    t.setSeconds(0, 0);
    for (let i = 0; i < 14 * 24 * 60; i++) {
      t.setMinutes(t.getMinutes() + 1);
      if (isActive(settings, t) !== current) return new Date(t);
    }
    return null;
  }

  function describeRule(r) {
    const days = (r.days || []).slice().sort().map((d) => DAY_NAMES[d]).join(', ') || 'no days';
    const time = r.start === r.end ? 'all day' : `${r.start}–${r.end}`;
    const range = r.from || r.to ? ` (${r.from || '…'} → ${r.to || '…'})` : '';
    return `${days}, ${time}${range}`;
  }

  global.LocaliaSchedule = { isActive, nextChange, describeRule, DAY_NAMES };
})(window);
