namespace RendezVousManager.Client;

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        // Une seule instance : un second lancement ramène simplement la fenêtre existante au premier plan.
        using var mutex = new Mutex(true, @"Local\RendezVousManager.Client", out bool first);
        using var show = new EventWaitHandle(false, EventResetMode.AutoReset, @"Local\RendezVousManager.Show");
        if (!first)
        {
            show.Set();
            return;
        }

        ApplicationConfiguration.Initialize();

        AppSettings settings;
        try
        {
            settings = AppSettings.Load();
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "RendezVous Manager", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return;
        }

        Application.Run(new MainForm(settings, show));
    }
}
