using System.Text.Json;

namespace RendezVousManager.Client;

/// <summary>
/// Réglages du client : seulement l'adresse du serveur. Aucun mot de passe, jeton ou clé n'est stocké.
/// Fichier optionnel : %APPDATA%\RendezVousManager\settings.json  →  { "url": "https://agenda.exemple.fr/" }
/// </summary>
internal sealed class AppSettings
{
    public const string DefaultUrl = "https://agenda.nicolasphilmon.fr/";

    public Uri Home { get; }

    private AppSettings(Uri home) => Home = home;

    public static string FilePath => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "RendezVousManager", "settings.json");

    public static AppSettings Load()
    {
        string url = DefaultUrl;
        if (File.Exists(FilePath))
        {
            try
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(FilePath));
                if (doc.RootElement.TryGetProperty("url", out var u) && u.ValueKind == JsonValueKind.String)
                {
                    url = u.GetString() ?? DefaultUrl;
                }
            }
            catch (Exception ex) when (ex is JsonException or IOException or UnauthorizedAccessException)
            {
                throw new InvalidOperationException($"Fichier de réglages illisible : {FilePath}\n\n{ex.Message}");
            }
        }

        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri) || !IsAllowed(uri))
        {
            throw new InvalidOperationException(
                $"Adresse du serveur refusée : « {url} ».\nUtilisez une adresse https:// (http:// n'est accepté que pour localhost).");
        }

        return new AppSettings(uri);
    }

    /// <summary>HTTPS obligatoire ; http seulement pour localhost (développement).</summary>
    private static bool IsAllowed(Uri uri) =>
        uri.Scheme == Uri.UriSchemeHttps
        || (uri.Scheme == Uri.UriSchemeHttp && (uri.Host == "localhost" || uri.Host == "127.0.0.1"));
}
