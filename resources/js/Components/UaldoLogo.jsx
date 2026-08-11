import React from 'react';

export default function UaldoLogo({ variant = 'dark', className = 'h-9 w-auto', ...props }) {
    let src = '/brand/ualdo-logo-oscuro.png';

    if (variant === 'light') {
        src = '/brand/ualdo-logo-claro.png';
    } else if (variant === 'isotipo') {
        src = '/brand/isotipo.png';
    }

    return (
        <img
            src={src}
            alt="Ualdo Business"
            className={`object-contain ${className}`}
            {...props}
        />
    );
}
