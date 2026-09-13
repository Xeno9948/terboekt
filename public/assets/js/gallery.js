// Gallery system with horizontal scrolling and lightbox functionality

document.addEventListener('DOMContentLoaded', function () {
    initScrollGallery();
    initLightbox();
    initSmoothScroll();
});

// Horizontal Scroll Gallery
function initScrollGallery() {
    const galleries = document.querySelectorAll('.scroll-gallery');

    galleries.forEach(gallery => {
        // Enable smooth scrolling with mouse wheel
        gallery.addEventListener('wheel', (e) => {
            if (e.deltaY !== 0) {
                e.preventDefault();
                gallery.scrollLeft += e.deltaY;
            }
        });

        // Touch/drag scrolling
        let isDown = false;
        let startX;
        let scrollLeft;

        gallery.addEventListener('mousedown', (e) => {
            isDown = true;
            gallery.style.cursor = 'grabbing';
            startX = e.pageX - gallery.offsetLeft;
            scrollLeft = gallery.scrollLeft;
        });

        gallery.addEventListener('mouseleave', () => {
            isDown = false;
            gallery.style.cursor = 'grab';
        });

        gallery.addEventListener('mouseup', () => {
            isDown = false;
            gallery.style.cursor = 'grab';
        });

        gallery.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - gallery.offsetLeft;
            const walk = (x - startX) * 2;
            gallery.scrollLeft = scrollLeft - walk;
        });

        // Set cursor
        gallery.style.cursor = 'grab';
    });
}

// Lightbox functionality
function initLightbox() {
    const galleryImages = document.querySelectorAll('.grid-item, .scroll-gallery-item img');

    // Create lightbox elements
    const lightbox = document.createElement('div');
    lightbox.className = 'lightbox';
    lightbox.innerHTML = `
        <div class="lightbox-backdrop"></div>
        <div class="lightbox-content">
            <button class="lightbox-close">&times;</button>
            <button class="lightbox-prev">&larr;</button>
            <button class="lightbox-next">&rarr;</button>
            <img src="" alt="">
        </div>
    `;
    document.body.appendChild(lightbox);

    const lightboxImg = lightbox.querySelector('img');
    const closeBtn = lightbox.querySelector('.lightbox-close');
    const prevBtn = lightbox.querySelector('.lightbox-prev');
    const nextBtn = lightbox.querySelector('.lightbox-next');
    let currentIndex = 0;
    let images = [];

    // Collect all gallery images
    galleryImages.forEach((img, index) => {
        const src = img.tagName === 'IMG' ? img.src : img.style.backgroundImage;
        images.push(img);

        img.addEventListener('click', () => {
            currentIndex = index;
            openLightbox(img.src || img.querySelector('img')?.src);
        });
    });

    function openLightbox(src) {
        lightboxImg.src = src;
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightbox.classList.remove('active');
        document.body.style.overflow = '';
    }

    function showNext() {
        currentIndex = (currentIndex + 1) % images.length;
        const nextImg = images[currentIndex];
        lightboxImg.src = nextImg.src || nextImg.querySelector('img')?.src;
    }

    function showPrev() {
        currentIndex = (currentIndex - 1 + images.length) % images.length;
        const prevImg = images[currentIndex];
        lightboxImg.src = prevImg.src || prevImg.querySelector('img')?.src;
    }

    // Event listeners
    closeBtn.addEventListener('click', closeLightbox);
    lightbox.querySelector('.lightbox-backdrop').addEventListener('click', closeLightbox);
    prevBtn.addEventListener('click', showPrev);
    nextBtn.addEventListener('click', showNext);

    // Keyboard navigation
    document.addEventListener('keydown', (e) => {
        if (!lightbox.classList.contains('active')) return;

        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowLeft') showPrev();
        if (e.key === 'ArrowRight') showNext();
    });
}

// Smooth scroll for anchor links
function initSmoothScroll() {
    const scrollIndicator = document.querySelector('.scroll-indicator');

    if (scrollIndicator) {
        scrollIndicator.addEventListener('click', () => {
            const nextSection = document.querySelector('.intro-section');
            if (nextSection) {
                nextSection.scrollIntoView({ behavior: 'smooth' });
            }
        });
    }

    // Add scrolled class to header
    const header = document.querySelector('header');
    window.addEventListener('scroll', () => {
        if (window.scrollY > 100) {
            header?.classList.add('scrolled');
        } else {
            header?.classList.remove('scrolled');
        }
    });
}

// Lazy loading for images
function initLazyLoading() {
    const images = document.querySelectorAll('img[data-src]');

    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
                observer.unobserve(img);
            }
        });
    });

    images.forEach(img => imageObserver.observe(img));
}

// Mobile menu toggle
const mobileToggle = document.querySelector('.mobile-toggle');
const mainNav = document.querySelector('.main-nav');

if (mobileToggle && mainNav) {
    mobileToggle.addEventListener('click', () => {
        mainNav.classList.toggle('active');
    });
}
