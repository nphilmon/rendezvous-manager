; Installeur Inno Setup du client Windows RendezVous Manager.
; Compilation (par le workflow GitHub) : iscc /DMyAppVersion=4.0.0 client-windows\installer.iss
#ifndef MyAppVersion
  #define MyAppVersion "4.0.0"
#endif

[Setup]
AppId={{6F2B9C1E-4A7D-4E58-9B63-2D1C8A5F7E40}
AppName=RendezVous Manager
AppVersion={#MyAppVersion}
AppPublisher=RendezVous Manager
DefaultDirName={autopf}\RendezVous Manager
DefaultGroupName=RendezVous Manager
DisableProgramGroupPage=yes
; Installation par défaut pour l'utilisateur seul (aucun droit administrateur requis) ; l'utilisateur peut choisir « pour tous ».
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputDir=..\installer-out
OutputBaseFilename=RendezVousManager-Setup-{#MyAppVersion}
SetupIconFile=app.ico
UninstallDisplayIcon={app}\RendezVousManager.exe
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
CloseApplications=yes
RestartApplications=no

[Languages]
Name: "french"; MessagesFile: "compiler:Languages\French.isl"

[Tasks]
Name: "desktopicon"; Description: "Créer une icône sur le Bureau"; GroupDescription: "Raccourcis :"
Name: "startup"; Description: "Lancer automatiquement à l'ouverture de session Windows"; GroupDescription: "Démarrage :"; Flags: unchecked

[Files]
Source: "..\out\RendezVousManager.exe"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{autoprograms}\RendezVous Manager"; Filename: "{app}\RendezVousManager.exe"
Name: "{autodesktop}\RendezVous Manager"; Filename: "{app}\RendezVousManager.exe"; Tasks: desktopicon
Name: "{userstartup}\RendezVous Manager"; Filename: "{app}\RendezVousManager.exe"; Tasks: startup

[Run]
Filename: "{app}\RendezVousManager.exe"; Description: "Lancer RendezVous Manager"; Flags: nowait postinstall skipifsilent
