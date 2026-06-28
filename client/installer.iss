; ArmaLogs Desktop Client Installer
; Build with:
;   "C:\Program Files (x86)\Inno Setup 6\iscc.exe" client\installer.iss

#define MyAppName "ArmaLogs Client"
#define MyAppVersion "1.2.1"
#define MyAppPublisher "ArmaLogs"
#define MyAppURL "https://armalogs.reichel.network"
#define MyAppExeName "ArmaLogsClient.exe"

[Setup]
AppId={{A7B2C4D5-E6F7-8901-2345-6789ABCDEF01}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
DefaultDirName={localappdata}\ArmaLogsClient
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
OutputDir=dist
OutputBaseFilename=ArmaLogsClientSetup
Compression=lzma
SolidCompression=yes
WizardStyle=modern
UninstallDisplayIcon={app}\{#MyAppExeName}

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked
Name: "launch"; Description: "Launch ArmaLogs Client after installation"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked
Name: "autostart"; Description: "Start ArmaLogs Client with Windows"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked

[Files]
Source: "dist\{#MyAppExeName}"; DestDir: "{app}"; Flags: ignoreversion
Source: "config.json.example"; DestDir: "{app}"; DestName: "config.json.example"; Flags: ignoreversion
Source: "README.md"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{autoprograms}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon
Name: "{autostartup}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; Tasks: autostart

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Launch {#MyAppName}"; Flags: nowait postinstall skipifsilent; Tasks: launch

[UninstallDelete]
Type: filesandordirs; Name: "{localappdata}\ArmaLogsClient"
Type: filesandordirs; Name: "{%USERPROFILE}\.armalogs"

[Registry]
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "ArmaLogsClient"; ValueData: """{app}\{#MyAppExeName}"""; Tasks: autostart; Flags: uninsdeletevalue