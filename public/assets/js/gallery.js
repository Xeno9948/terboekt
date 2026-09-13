document.addEventListener('DOMContentLoaded', function () {
    initLightbox();
});

function initLightbox() {
    const galleryImages = Array.from(document.querySelectorAll('.js-lightbox img, .js-lightbox, .photo-grid img, .room-img img, .attr-img img'))
        .filter((el) => el.tagName === 'IMG' && !el.closest('[data-photo-switch]'));
    if (!galleryImages.length) return;

    const lightbox = document.createElement('div');
    lightbox.className = 'lightbox';
    lightbox.setAttribute('role', 'dialog');
    lightbox.setAttribute('aria-modal', 'true');
    lightbox.setAttribute('aria-label', 'Photo');
    lightbox.innerHTML = `
        <div class="lightbox-backdrop"></div>
        <div class="lightbox-content">
            <button class="lightbox-close" type="button" aria-label="Close">&times;</button>
            <button class="lightbox-prev" type="button" aria-label="Previous">&larr;</button>
            <button class="lightbox-next" type="button" aria-label="Next">&rarr;</button>
            <img src="" alt="">
        </div>
    `;
    document.body.appendChild(lightbox);

    const lightboxImg = lightbox.querySelector('img');
    let currentIndex = 0;

    function srcOf(el) {
        return el.tagName === 'IMG' ? el.src : el.querySelector('img')?.src;
    }

    function openLightbox(index) {
        currentIndex = index;
        const img = galleryImages[currentIndex];
        lightboxImg.src = srcOf(img);
        lightboxImg.alt = img.alt || '';
        lightbox.classList.add('active');
        document.body.classList.add('lightbox-open');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightbox.classList.remove('active');
        document.body.classList.remove('lightbox-open');
        document.body.style.overflow = '';
    }

    galleryImages.forEach((img, index) => {
        img.style.cursor = 'pointer';
        img.addEventListener('click', () => openLightbox(index));
    });

    lightbox.querySelector('.lightbox-close').addEventListener('click', closeLightbox);
    lightbox.querySelector('.lightbox-backdrop').addEventListener('click', closeLightbox);
    lightbox.querySelector('.lightbox-next').addEventListener('click', () => {
        openLightbox((currentIndex + 1) % galleryImages.length);
    });
    lightbox.querySelector('.lightbox-prev').addEventListener('click', () => {
        openLightbox((currentIndex - 1 + galleryImages.length) % galleryImages.length);
    });

    document.addEventListener('keydown', (e) => {
        if (!lightbox.classList.contains('active')) return;
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowRight') openLightbox((currentIndex + 1) % galleryImages.length);
        if (e.key === 'ArrowLeft') openLightbox((currentIndex - 1 + galleryImages.length) % galleryImages.length);
    });
}
