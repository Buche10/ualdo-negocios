import React from 'react';

export default function UaldoMascot({ className = 'w-48 h-auto', showGlow = true }) {
    return (
        <div className="relative inline-block group">
            {showGlow && (
                <div className="absolute -inset-4 bg-gradient-to-r from-ualdo-turquesa/30 to-ualdo-teal/30 rounded-full blur-xl opacity-75 group-hover:opacity-100 transition duration-1000 group-hover:duration-200 animate-pulse" />
            )}
            <img
                src="/brand/mascota.png"
                alt="Ualdo Asistente"
                className={`relative object-contain transition-transform duration-500 hover:scale-105 animate-[float_4s_ease-in-out_infinite] ${className}`}
            />
        </div>
    );
}
