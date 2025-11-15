<style>
    #backToTopBtn {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 9999;
        background: #4338ca;
        color: white;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        font-size: 24px;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        transition: 0.3s ease;
    }

    #backToTopBtn:hover {
        background: #4338ca;
        transform: scale(1.1);
    }
</style>

<div id="backToTopBtn" onclick="window.scrollTo({ top: 0, behavior: 'smooth' })">
    ↑
</d
