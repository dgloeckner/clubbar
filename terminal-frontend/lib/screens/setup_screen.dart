import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/services/config_service.dart';
import 'package:ruderbar_terminal/services/network_service.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';

class SetupScreen extends StatefulWidget {
  final ConfigService configService;

  const SetupScreen({super.key, required this.configService});

  @override
  State<SetupScreen> createState() => _SetupScreenState();
}

class _SetupScreenState extends State<SetupScreen> {
  final _formKey = GlobalKey<FormState>();
  final _terminalIdController = TextEditingController();
  final _apiUrlController = TextEditingController();
  final _apiTokenController = TextEditingController();

  bool _isConnecting = false;
  String? _errorMessage;

  @override
  void dispose() {
    _terminalIdController.dispose();
    _apiUrlController.dispose();
    _apiTokenController.dispose();
    super.dispose();
  }

  String? _validateTerminalId(String? value, AppLocalizations l10n) {
    if (value == null || value.trim().isEmpty) {
      return l10n.terminalIdRequired;
    }
    return null;
  }

  String? _validateApiUrl(String? value, AppLocalizations l10n) {
    if (value == null || value.trim().isEmpty) {
      return l10n.apiUrlRequired;
    }
    final uri = Uri.tryParse(value.trim());
    if (uri == null || !uri.hasScheme || !uri.hasAuthority) {
      return l10n.apiUrlInvalid;
    }
    return null;
  }

  String? _validateApiToken(String? value, AppLocalizations l10n) {
    if (value == null || value.trim().isEmpty) {
      return l10n.apiTokenRequired;
    }
    return null;
  }

  Future<void> _saveAndConnect() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isConnecting = true;
      _errorMessage = null;
    });

    try {
      final apiUrl = _apiUrlController.text.trim();
      final apiToken = _apiTokenController.text.trim();
      final terminalId = _terminalIdController.text.trim();

      // Test connection by syncing members (validates both URL and token)
      final testService = NetworkService(baseUrl: apiUrl);
      testService.setAuthToken(apiToken);
      await testService.syncMembers();

      // Connection successful — save config
      await widget.configService.save(
        terminalId: terminalId,
        apiUrl: apiUrl,
        apiToken: apiToken,
      );

      if (mounted) {
        // Update the app's NetworkService with the new credentials
        final appNetworkService = context.read<NetworkService>();
        appNetworkService.setBaseUrl(apiUrl);
        appNetworkService.setAuthToken(apiToken);

        // Trigger initial sync to populate local database
        context.read<SyncProvider>().startBackgroundSync();

        context.go('/idle');
      }
    } on NetworkException catch (e) {
      if (mounted) {
        final l10n = AppLocalizations.of(context)!;
        setState(() {
          _errorMessage = l10n.connectionFailed(e.message);
        });
      }
    } catch (e) {
      if (mounted) {
        final l10n = AppLocalizations.of(context)!;
        setState(() {
          _errorMessage = l10n.connectionFailed(e.toString());
        });
      }
    } finally {
      if (mounted) {
        setState(() {
          _isConnecting = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return Scaffold(
      backgroundColor: const Color(0xFF0a1628),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(AppSpacing.xxxl),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Form(
              key: _formKey,
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // Title
                  Text(
                    l10n.setupTitle,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Color(0xfff1f5f9),
                      fontSize: 32,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  Text(
                    l10n.setupSubtitle,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Color(0xff94a3b8),
                      fontSize: AppFontSizes.lg,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.xxxl),

                  // Terminal ID field
                  _buildLabel(l10n.terminalIdLabel),
                  const SizedBox(height: AppSpacing.xs),
                  TextFormField(
                    controller: _terminalIdController,
                    validator: (value) => _validateTerminalId(value, l10n),
                    style: const TextStyle(color: Color(0xfff1f5f9)),
                    decoration: _inputDecoration(
                      hintText: 'e.g. Ruderbar-Kühlschrank',
                    ),
                  ),
                  const SizedBox(height: AppSpacing.xl),

                  // API URL field
                  _buildLabel(l10n.apiUrlLabel),
                  const SizedBox(height: AppSpacing.xs),
                  TextFormField(
                    controller: _apiUrlController,
                    validator: (value) => _validateApiUrl(value, l10n),
                    style: const TextStyle(color: Color(0xfff1f5f9)),
                    keyboardType: TextInputType.url,
                    decoration: _inputDecoration(
                      hintText: 'https://club.example.com/api',
                    ),
                  ),
                  const SizedBox(height: AppSpacing.xl),

                  // API Token field
                  _buildLabel(l10n.apiTokenLabel),
                  const SizedBox(height: AppSpacing.xs),
                  TextFormField(
                    controller: _apiTokenController,
                    validator: (value) => _validateApiToken(value, l10n),
                    style: const TextStyle(color: Color(0xfff1f5f9)),
                    obscureText: true,
                    decoration: _inputDecoration(
                      hintText: 'Paste token from admin panel',
                    ),
                  ),
                  const SizedBox(height: AppSpacing.xxxl),

                  // Error message
                  if (_errorMessage != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.lg),
                      child: Text(
                        _errorMessage!,
                        style: const TextStyle(
                          color: Color(0xffef4444),
                          fontSize: AppFontSizes.base,
                        ),
                        textAlign: TextAlign.center,
                      ),
                    ),

                  // Save & Connect button
                  SizedBox(
                    height: 48,
                    child: ElevatedButton(
                      onPressed: _isConnecting ? null : _saveAndConnect,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xff3b82f6),
                        disabledBackgroundColor: const Color(0xff334155),
                        shape: RoundedRectangleBorder(
                          borderRadius:
                              BorderRadius.circular(AppBorderRadius.md),
                        ),
                      ),
                      child: _isConnecting
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
                              ),
                            )
                          : Text(
                              l10n.saveAndConnect,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: AppFontSizes.lg,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildLabel(String text) {
    return Text(
      text,
      style: const TextStyle(
        color: Color(0xff94a3b8),
        fontSize: AppFontSizes.sm,
        fontWeight: FontWeight.w500,
      ),
    );
  }

  InputDecoration _inputDecoration({required String hintText}) {
    return InputDecoration(
      hintText: hintText,
      hintStyle: const TextStyle(color: Color(0xff64748b)),
      filled: true,
      fillColor: const Color(0xff0d1829),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppBorderRadius.sm),
        borderSide: const BorderSide(color: Color(0xff334155)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppBorderRadius.sm),
        borderSide: const BorderSide(color: Color(0xff334155)),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppBorderRadius.sm),
        borderSide: const BorderSide(color: Color(0xff3b82f6)),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppBorderRadius.sm),
        borderSide: const BorderSide(color: Color(0xffef4444)),
      ),
      contentPadding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.lg,
        vertical: AppSpacing.md,
      ),
    );
  }
}
