package com.example.gmu_voicebot_flutter

import android.content.Context
import android.media.AudioDeviceInfo
import android.media.AudioManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    private val channel = "gmu.voicebot/audio"

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channel).setMethodCallHandler { call, result ->
            val audioManager = getSystemService(Context.AUDIO_SERVICE) as AudioManager
            when (call.method) {
                "setSpeakerphone" -> {
                    // MODE_IN_COMMUNICATION activates hardware AEC (echo canceller) on all Android
                    // versions — this is the same mode WhatsApp/Zoom use. Must be set BEFORE
                    // routing to speaker so the OS applies AEC to the chosen output device.
                    audioManager.mode = AudioManager.MODE_IN_COMMUNICATION
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                        // Android 12+: use setCommunicationDevice to route to loudspeaker.
                        // isSpeakerphoneOn is deprecated and ignored by WebRTC on Android 12+.
                        val speaker = audioManager.availableCommunicationDevices
                            .firstOrNull { it.type == AudioDeviceInfo.TYPE_BUILTIN_SPEAKER }
                        if (speaker != null) {
                            audioManager.setCommunicationDevice(speaker)
                        }
                        // Max out volume on both streams
                        val maxVoice = audioManager.getStreamMaxVolume(AudioManager.STREAM_VOICE_CALL)
                        audioManager.setStreamVolume(AudioManager.STREAM_VOICE_CALL, maxVoice, 0)
                        val maxMusic = audioManager.getStreamMaxVolume(AudioManager.STREAM_MUSIC)
                        audioManager.setStreamVolume(AudioManager.STREAM_MUSIC, maxMusic, 0)
                        // Re-assert after 600ms — WebRTC finishes audio init ~500ms after call-start
                        Handler(Looper.getMainLooper()).postDelayed({
                            audioManager.mode = AudioManager.MODE_IN_COMMUNICATION
                            val s = audioManager.availableCommunicationDevices
                                .firstOrNull { it.type == AudioDeviceInfo.TYPE_BUILTIN_SPEAKER }
                            if (s != null) audioManager.setCommunicationDevice(s)
                        }, 600)
                    } else {
                        // Android < 12: MODE_IN_COMMUNICATION + isSpeakerphoneOn = AEC + loudspeaker
                        audioManager.isSpeakerphoneOn = true
                        val maxMusic = audioManager.getStreamMaxVolume(AudioManager.STREAM_MUSIC)
                        audioManager.setStreamVolume(AudioManager.STREAM_MUSIC, maxMusic, 0)
                        Handler(Looper.getMainLooper()).postDelayed({
                            audioManager.mode = AudioManager.MODE_IN_COMMUNICATION
                            audioManager.isSpeakerphoneOn = true
                        }, 600)
                    }
                    result.success(null)
                }
                "setEarpiece" -> {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                        audioManager.clearCommunicationDevice()
                    } else {
                        audioManager.mode = AudioManager.MODE_IN_COMMUNICATION
                        audioManager.isSpeakerphoneOn = false
                    }
                    result.success(null)
                }
                "resetAudio" -> {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                        audioManager.clearCommunicationDevice()
                    }
                    audioManager.isSpeakerphoneOn = false
                    audioManager.mode = AudioManager.MODE_NORMAL
                    result.success(null)
                }
                else -> result.notImplemented()
            }
        }
    }
}
