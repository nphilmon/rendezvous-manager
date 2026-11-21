using System.Diagnostics;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace RendezVousManager.Client;

/// <summary>
/// Fenêtre native qui affiche l'application web. Toute la logique (droits, synchronisation, notifications, données)
/// reste sur le serveur : le client ne conserve aucun secret et ne désactive jamais la vérification TLS.
/// </summary>
internal sealed class MainForm : Form
{
    private readonly AppSettings _settings;
    private readonly WebView2 _web = new();
    private readonly NotifyIcon _tray = new();
    private bool _quit;
    private bool _trayHintShown;

    public MainForm(AppSettings settings, EventWaitHandle showSignal)
    {
        _settings = settings;
        Text = "RendezVous Manager";
        Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath);
        StartPosition = FormStartPosition.CenterScreen;
        Size = new Size(1280, 820);
        MinimumSize = new Size(480, 600);

        _web.Dock = DockStyle.Fill;
        Controls.Add(_web);

        var menu = new ContextMenuStrip();
        menu.Items.Add("Ouvrir", null, (_, _) => ShowMain());
        menu.Items.Add("Actualiser", null, (_, _) => _web.CoreWebView2?.Reload());
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("Quitter", null, (_, _) => { _quit = true; Close(); });
        _tray.Icon = Icon;
        _tray.Text = "RendezVous Manager";
        _tray.ContextMenuStrip = menu;
        _tray.DoubleClick += (_, _) => ShowMain();
        _tray.Visible = true;

        // Un second lancement du programme demande l'affichage de cette fenêtre.
        var listener = new Thread(() =>
        {
            while (showSignal.WaitOne())
            {
                try { BeginInvoke(ShowMain); } catch (InvalidOperationException) { return; }
            }
        }) { IsBackground = true };
        listener.Start();

        Load += async (_, _) => await InitAsync();
    }

    private void ShowMain()
    {
        Show();
        if (WindowState == FormWindowState.Minimized) WindowState = FormWindowState.Normal;
        Activate();
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        // La croix réduit dans la zone de notification ; « Quitter » du menu ferme réellement le programme.
        if (!_quit && e.CloseReason == CloseReason.UserClosing)
        {
            e.Cancel = true;
            Hide();
            if (!_trayHintShown)
            {
                _trayHintShown = true;
                _tray.ShowBalloonTip(3000, "RendezVous Manager", "Le programme reste actif dans la zone de notification.", ToolTipIcon.Info);
            }
            return;
        }
        _tray.Visible = false;
        _tray.Dispose();
        base.OnFormClosing(e);
    }

    private async Task InitAsync()
    {
        try
        {
            string data = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "RendezVousManager", "WebView2");
            var env = await CoreWebView2Environment.CreateAsync(null, data);
            await _web.EnsureCoreWebView2Async(env);
        }
        catch (Exception ex) when (ex is WebView2RuntimeNotFoundException or InvalidOperationException or System.Runtime.InteropServices.COMException)
        {
            MessageBox.Show(
                "Le composant Microsoft Edge WebView2 est introuvable ou inutilisable.\n\n" +
                "Installez « WebView2 Runtime » depuis https://developer.microsoft.com/microsoft-edge/webview2/ puis relancez le programme.\n\n" + ex.Message,
                "RendezVous Manager", MessageBoxButtons.OK, MessageBoxIcon.Error);
            _quit = true;
            Close();
            return;
        }

        var core = _web.CoreWebView2;
        core.Settings.AreDevToolsEnabled = false;
        core.Settings.IsStatusBarEnabled = false;
        core.Settings.IsZoomControlEnabled = true;

        core.NavigationStarting += OnNavigationStarting;
        core.NewWindowRequested += OnNewWindowRequested;
        core.NavigationCompleted += OnNavigationCompleted;
        core.WebMessageReceived += (_, e) =>
        {
            if (e.TryGetWebMessageAsString() == "retry") core.Navigate(_settings.Home.AbsoluteUri);
        };
        core.DocumentTitleChanged += (_, _) =>
        {
            string t = core.DocumentTitle;
            Text = string.IsNullOrWhiteSpace(t) ? "RendezVous Manager" : t;
        };

        core.Navigate(_settings.Home.AbsoluteUri);
    }

    /// <summary>Seul le site configuré s'affiche dans la fenêtre ; le reste s'ouvre dans le navigateur par défaut.</summary>
    private bool IsInternal(Uri uri) =>
        uri.Scheme == _settings.Home.Scheme
        && string.Equals(uri.Host, _settings.Home.Host, StringComparison.OrdinalIgnoreCase)
        && uri.Port == _settings.Home.Port;

    private void OnNavigationStarting(object? sender, CoreWebView2NavigationStartingEventArgs e)
    {
        if (!Uri.TryCreate(e.Uri, UriKind.Absolute, out var uri)) { e.Cancel = true; return; }
        if (uri.Scheme is "about" or "data") return; // page d'erreur locale générée par ce programme
        if (IsInternal(uri)) return;
        e.Cancel = true;
        OpenExternal(uri);
    }

    private void OnNewWindowRequested(object? sender, CoreWebView2NewWindowRequestedEventArgs e)
    {
        e.Handled = true;
        if (!Uri.TryCreate(e.Uri, UriKind.Absolute, out var uri)) return;
        if (IsInternal(uri)) _web.CoreWebView2.Navigate(uri.AbsoluteUri);
        else OpenExternal(uri);
    }

    private static void OpenExternal(Uri uri)
    {
        // Seuls http(s) sont transmis au système ; jamais file:, javascript: ou un protocole personnalisé.
        if (uri.Scheme != Uri.UriSchemeHttps && uri.Scheme != Uri.UriSchemeHttp) return;
        try { Process.Start(new ProcessStartInfo(uri.AbsoluteUri) { UseShellExecute = true }); }
        catch (Exception ex) when (ex is System.ComponentModel.Win32Exception or InvalidOperationException) { }
    }

    private void OnNavigationCompleted(object? sender, CoreWebView2NavigationCompletedEventArgs e)
    {
        if (e.IsSuccess || e.WebErrorStatus == CoreWebView2WebErrorStatus.OperationCanceled) return;
        string detail = System.Net.WebUtility.HtmlEncode(e.WebErrorStatus.ToString());
        string host = System.Net.WebUtility.HtmlEncode(_settings.Home.Host);
        _web.CoreWebView2.NavigateToString(
            "<!doctype html><html lang='fr'><meta charset='utf-8'><title>Serveur injoignable</title>" +
            "<body style='font-family:Segoe UI,sans-serif;text-align:center;margin-top:18vh;color:#1f2937'>" +
            "<h1>Serveur injoignable</h1>" +
            $"<p>Impossible de joindre <b>{host}</b>.</p><p style='color:#6b7280'>Vérifiez votre connexion Internet. ({detail})</p>" +
            "<button style='font-size:16px;padding:10px 22px' onclick=\"window.chrome.webview.postMessage('retry')\">Réessayer</button></body></html>");
    }
}
