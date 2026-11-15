package com.flashpay.gateway

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.foundation.shape.RoundedCornerShape

object Brand {
    val Navy = Color(0xFF0B1224)
    val Blue = Color(0xFF0B5FFF)
    val BlueLight = Color(0xFF3B82F6)
    val Green = Color(0xFF0F9D58)
    val GreenSoft = Color(0xFFE6F6EE)
    val Amber = Color(0xFFB45309)
    val AmberSoft = Color(0xFFFFF4E0)
    val Red = Color(0xFFD93025)
    val RedSoft = Color(0xFFFDECEA)
    val BlueSoft = Color(0xFFE8EFFF)
}

private val LightColors = lightColorScheme(
    primary = Brand.Blue,
    onPrimary = Color.White,
    primaryContainer = Brand.BlueSoft,
    onPrimaryContainer = Color(0xFF0A3A99),
    secondary = Brand.Navy,
    background = Color(0xFFF5F7FB),
    onBackground = Color(0xFF0F172A),
    surface = Color.White,
    onSurface = Color(0xFF0F172A),
    surfaceVariant = Color(0xFFF1F5F9),
    onSurfaceVariant = Color(0xFF64748B),
    outline = Color(0xFFE2E8F0),
    outlineVariant = Color(0xFFE2E8F0),
    error = Brand.Red,
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF6EA0FF),
    onPrimary = Color(0xFF00225F),
    primaryContainer = Color(0xFF16264A),
    onPrimaryContainer = Color(0xFFD7E3FF),
    secondary = Color(0xFFCBD5E1),
    background = Color(0xFF0B1020),
    onBackground = Color(0xFFE6EAF3),
    surface = Color(0xFF121A2E),
    onSurface = Color(0xFFE6EAF3),
    surfaceVariant = Color(0xFF1A2440),
    onSurfaceVariant = Color(0xFF94A3B8),
    outline = Color(0xFF22304D),
    outlineVariant = Color(0xFF22304D),
    error = Color(0xFFFF8A80),
)

@Composable
fun GatewayTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = if (isSystemInDarkTheme()) DarkColors else LightColors,
        shapes = Shapes(
            small = RoundedCornerShape(10.dp),
            medium = RoundedCornerShape(16.dp),
            large = RoundedCornerShape(22.dp),
        ),
        content = content,
    )
}
