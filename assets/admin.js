(function () {
  'use strict';

  var root = document.getElementById('momobird-admin');
  if (!root) return;

  var restUrl = root.getAttribute('data-rest-url');
  var nonce = root.getAttribute('data-nonce');
  var message = document.getElementById('momobird-message');
  var progress = document.getElementById('momobird-progress');
  var buttons = Array.prototype.slice.call(root.querySelectorAll('[data-momobird-action]'));
  var BATCH = 20;
  var routes = {
    'status': 'status',
    'test-connection': 'test',
    'start-sync': 'full-sync/start',
    'sync-page': 'full-sync/page',
    'cleanup-page': 'full-sync/cleanup',
    'retry-failures': 'retry',
    'enable-widget': 'widget/enable'
  };

  function setBusy(busy) {
    buttons.forEach(function (button) { button.disabled = busy; });
    progress.hidden = !busy;
  }

  function setMessage(text, kind) {
    message.textContent = text;
    message.setAttribute('data-kind', kind || '');
  }

  function errorText(payload) {
    if (payload && payload.message) return payload.message;
    return '请求失败，请稍后重试。';
  }

  function postAction(operation, values) {
    return fetch(restUrl + routes[operation], {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'X-WP-Nonce': nonce},
      body: JSON.stringify(values || {})
    }).then(function (response) {
      return response.json().catch(function () { return null; }).then(function (payload) {
        if (!response.ok || !payload || payload.ok === false) throw new Error(errorText(payload && payload.ok === false ? payload.error : payload));
        return payload;
      });
    });
  }

  function refreshStatus() {
    return postAction('status', {}).then(function (payload) {
      document.getElementById('momobird-config-state').textContent = payload.configuration_state === 'configured' ? '已配置' : '未配置';
      document.getElementById('momobird-connection-state').textContent = payload.connection_state === 'unconfigured' ? '不可用' : '待检测';
      document.getElementById('momobird-auto-sync').textContent = payload.auto_sync_enabled ? '已开启' : '已关闭（可手动同步）';
      document.getElementById('momobird-synced-posts').textContent = String(payload.synced_posts || 0);
      document.getElementById('momobird-plan-required').textContent = String(payload.counts.plan_required || 0);
      document.getElementById('momobird-failed-upsert').textContent = String(payload.counts.failed_upsert || 0);
      document.getElementById('momobird-failed-delete').textContent = String(payload.counts.failed_delete || 0);
      document.getElementById('momobird-last-sync').textContent = payload.last_full_sync_at
        ? new Date(payload.last_full_sync_at * 1000).toLocaleString()
        : '尚未执行';
      var recent = document.getElementById('momobird-recent-error');
      if (payload.recent_error) {
        recent.textContent = '最近错误：' + payload.recent_error.code + ' — ' + payload.recent_error.message;
        recent.hidden = false;
      } else {
        recent.hidden = true;
        recent.textContent = '';
      }
    });
  }

  var EXPIRED_TEXT = '试用已结束，自动同步和前台助手已暂停。请在 MomoBird 控制台点顶栏的套餐输入兑换码开通专业版';

  function planText(entitlement) {
    if (!entitlement || !entitlement.status) return '—';
    if (entitlement.status === 'trial' && entitlement.trial_ends_at) {
      var days = Math.ceil((new Date(entitlement.trial_ends_at).getTime() - Date.now()) / 86400000);
      return days <= 1 ? '专业版试用 · 今天到期' : '专业版试用 · 剩 ' + days + ' 天';
    }
    if (entitlement.status === 'pro' && entitlement.pro_ends_at) {
      return '专业版 · 至 ' + entitlement.pro_ends_at.slice(0, 10);
    }
    if (entitlement.status === 'expired') return EXPIRED_TEXT;
    return '未开通';
  }

  function showConnection(payload) {
    document.getElementById('momobird-connection-state').textContent = '已连接 · ' + ((payload.project && payload.project.name) || '');
    document.getElementById('momobird-plan').textContent = planText(payload.entitlement);
    var source = payload.source || {};
    document.getElementById('momobird-serving').textContent =
      '已上线 ' + (source.ready_document_count || 0) + ' / 已同步 ' + (source.document_count || 0);
    if (payload.origin_matches === false) {
      setMessage('站点地址 ' + payload.blog_origin + ' 与控制台里填写的 ' + (source.site_url || '') +
        ' 不一致，MomoBird 会拒绝这些文章。请在控制台按站点实际地址重新创建 WordPress 来源。', 'error');
      return false;
    }
    if (payload.kind_warning) {
      setMessage('这个连接密钥属于控制台里的 ' + (source.kind || '其他') + ' 来源，不是 WordPress 来源。可以继续使用，但建议在控制台新建 WordPress 来源后换上它的密钥。', 'error');
      return false;
    }
    return true;
  }

  function cleanupAll(token) {
    var cursor = null;
    var deleted = 0;
    function next() {
      return postAction('cleanup-page', {run_token: token, cursor: cursor}).then(function (page) {
        deleted += page.deleted || 0;
        if (page.done) return {deleted: deleted, widget: page.widget || null};
        cursor = page.next_cursor;
        return next();
      });
    }
    return next();
  }

  // The assistant is only turned on when 在前台显示问答助手 is checked; null means it was not requested.
  function widgetText(widget) {
    if (!widget) return '';
    if (widget.available) return '前台问答助手已开启。';
    if (widget.reason === 'not_published') return '文章索引完成后，前台问答助手会自动开启（有人访问站点时每 10 分钟检查一次）。';
    return '';
  }

  function runFullSync() {
    setBusy(true);
    progress.removeAttribute('value');
    setMessage('正在准备全量同步…');
    return postAction('start-sync', {}).then(function (run) {
      var cursor = 0;
      var processed = 0;
      var rejected = 0;
      var planRequired = false;
      function next() {
        return postAction('sync-page', {run_token: run.run_token, cursor: cursor, limit: BATCH}).then(function (page) {
          processed += page.processed || 0;
          rejected += page.rejected || 0;
          if (page.plan_required) planRequired = true;
          setMessage('已同步 ' + processed + ' 篇文章…');
          if (page.done) return cleanupAll(run.run_token);
          cursor = page.next_cursor;
          return next();
        });
      }
      return next().then(function (result) {
        var deleted = result.deleted;
        var widget = widgetText(result.widget);
        if (planRequired) {
          setMessage(EXPIRED_TEXT + '。已下线 ' + deleted + ' 篇不再公开的文章。', 'error');
        } else if (rejected > 0) {
          setMessage('全量同步完成，但有 ' + rejected + ' 篇文章被拒绝（见失败计数和最近错误）；已下线 ' + deleted + ' 篇不再公开的文章。' + widget, 'error');
        } else {
          setMessage('全量同步完成，已下线 ' + deleted + ' 篇不再公开的文章。文章索引完成后即可在问答中使用。' + widget, 'success');
        }
        return refreshStatus();
      });
    }).catch(function (error) {
      setMessage(error.message, 'error');
    }).then(function () {
      setBusy(false);
      progress.hidden = true;
    });
  }

  var simple = {
    test: {operation: 'test-connection', done: function () { return '连接成功。'; }},
    retry: {operation: 'retry-failures', done: function (payload) { return '失败项重试完成，共处理 ' + (payload.retried || 0) + ' 项。'; }},
    widget: {operation: 'enable-widget', done: function (payload) { return widgetText(payload) || '前台问答助手暂未开启：' + (payload.reason || ''); }}
  };

  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      var action = button.getAttribute('data-momobird-action');
      if (action === 'sync') {
        runFullSync();
        return;
      }
      setBusy(true);
      postAction(simple[action].operation, {}).then(function (payload) {
        var fine = action === 'test' ? showConnection(payload) : true;
        if (fine) setMessage(simple[action].done(payload), 'success');
        return refreshStatus();
      }).catch(function (error) {
        setMessage(error.message, 'error');
      }).then(function () {
        setBusy(false);
        progress.hidden = true;
      });
    });
  });

  refreshStatus().then(function () {
    if (document.getElementById('momobird-config-state').textContent === '已配置') {
      return postAction('test-connection', {}).then(showConnection).catch(function () {});
    }
  }).then(function () {
    if (!message.getAttribute('data-kind')) setMessage('状态已更新。');
  }).catch(function (error) {
    setMessage(error.message, 'error');
  });
}());
